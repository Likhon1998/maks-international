<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PartnerInquiry;
use App\Models\Payment;
use App\Models\ProductReturn;
use App\Models\Shop;
use App\Models\User;
use App\Support\Warranty;
use App\Notifications\SystemAlert;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

class AppNotificationService
{
    public function adminRecipients(): Collection
    {
        return User::query()
            ->where('portal', User::PORTAL_ADMIN)
            ->where('is_active', true)
            ->get();
    }

    public function notifyAdmins(string $title, string $body, ?string $url = null, string $category = 'system', string $level = 'info'): void
    {
        $recipients = $this->adminRecipients();
        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new SystemAlert($title, $body, $url, $category, $level));
    }

    public function orderPendingAudit(Order $order): void
    {
        $this->notifyAdmins(
            "Order {$order->number} awaiting audit",
            ($order->shop?->name ?: 'Shop').' · ৳ '.number_format((float) $order->total, 2).' · '.str_replace('_', ' ', $order->source),
            '/admin/orders?audit_queue=1&view='.$order->id,
            'orders',
            'warning',
        );
    }

    public function paymentPending(Payment $payment): void
    {
        $this->notifyAdmins(
            "Payment {$payment->number} pending verification",
            ($payment->shop?->name ?: 'Shop').' · ৳ '.number_format((float) $payment->amount, 2),
            '/admin/payments?status=pending',
            'payments',
            'info',
        );
    }

    public function commissionAccrued(\App\Models\Commission $commission): void
    {
        $commission->loadMissing(['salesman', 'payment']);

        $this->notifyAdmins(
            "Commission {$commission->number} accrued",
            ($commission->salesman?->name ?: 'Salesman')
                .' · '.$commission->typeLabel()
                .' · ৳ '.number_format((float) $commission->commission_amount, 2)
                .($commission->payment ? ' · '.$commission->payment->number : ''),
            '/admin/commissions?status=accrued',
            'commissions',
            'info',
        );
    }

    public function returnSubmitted(ProductReturn $return): void
    {
        $this->notifyAdmins(
            "Return {$return->number} submitted",
            ($return->shop?->name ?: 'Shop').' · '.$return->reasonTypeLabel().' · ৳ '.number_format((float) $return->total, 2),
            '/admin/returns/'.$return->id,
            'returns',
            'warning',
        );
    }

    public function shopAddedFromField(Shop $shop, User $salesman): void
    {
        $this->notifyAdmins(
            "New shop added by {$salesman->name}",
            $shop->name.' · '.($shop->city ?: 'No area').' · '.$shop->code.' · waiting for approval',
            '/admin/shops/'.$shop->id,
            'shops',
            'warning',
        );
    }

    public function partnerFromField(PartnerInquiry $inquiry, User $salesman): void
    {
        $this->notifyAdmins(
            "Partner application from {$salesman->name}",
            $inquiry->business_name.' · '.($inquiry->city ?: 'No area').' · submitted from the field',
            '/admin/partner-leads?status=pending',
            'partners',
            'info',
        );
    }

    public function notifyUser(?User $user, string $title, string $body, ?string $url = null, string $category = 'system', string $level = 'info'): void
    {
        if (! $user || ! $user->is_active) {
            return;
        }

        $user->notify(new SystemAlert($title, $body, $url, $category, $level));
    }

    public function orderApproved(Order $order): void
    {
        $order->loadMissing(['shop', 'salesman']);
        $this->notifyUser(
            $order->salesman,
            "Order {$order->number} approved",
            ($order->shop?->name ?: 'Shop').' · ৳ '.number_format((float) $order->total, 2).' · stock reserved',
            '/field/orders/'.$order->id,
            'orders',
            'success',
        );
    }

    public function orderDelivered(Order $order): void
    {
        $order->loadMissing(['shop.users', 'salesman', 'invoice', 'advanceInvoice']);
        $due = (float) $order->settlement()['remaining_due'];
        $dueNote = $due > 0.009
            ? ' · ৳ '.number_format($due, 2).' now due'
            : '';

        $this->notifyUser(
            $order->salesman,
            "Order {$order->number} delivered",
            ($order->shop?->name ?: 'Shop').' · goods delivered'.$dueNote,
            '/field/orders/'.$order->id,
            'deliveries',
            'success',
        );

        foreach ($order->shop?->users ?? [] as $shopUser) {
            $this->notifyUser(
                $shopUser,
                "Order {$order->number} delivered",
                $due > 0.009
                    ? 'Goods delivered. Invoice is ready — ৳ '.number_format($due, 2).' due.'
                    : 'Your order has been delivered.',
                '/portal/orders/'.$order->id,
                'deliveries',
                'success',
            );
        }
    }

    public function paymentVerified(Payment $payment): void
    {
        $payment->loadMissing(['shop.users', 'shop.assignedSalesman']);
        $shop = $payment->shop;
        if (! $shop) {
            return;
        }

        foreach ($shop->users as $shopUser) {
            $this->notifyUser(
                $shopUser,
                "Payment {$payment->number} verified",
                '৳ '.number_format((float) $payment->amount, 2).' applied · outstanding ৳ '.number_format((float) $shop->fresh()->outstanding_balance, 2),
                '/portal/statement',
                'payments',
                'success',
            );
        }

        $this->notifyUser(
            $shop->assignedSalesman,
            "Payment collected · {$shop->name}",
            '৳ '.number_format((float) $payment->amount, 2).' verified',
            '/field/shops',
            'payments',
            'info',
        );
    }

    public function targetAchieved(User $salesman, float $achieved, float $target): void
    {
        $this->notifyUser(
            $salesman,
            'Congratulations — monthly target achieved!',
            'Sales ৳ '.number_format($achieved, 2).' of ৳ '.number_format($target, 2),
            '/field/dashboard',
            'targets',
            'success',
        );
    }

    public function seedOperationalAlerts(): int
    {
        $count = 0;

        $pendingOrders = Order::query()->where('status', Order::STATUS_PENDING_AUDIT)->count();
        if ($pendingOrders > 0) {
            $this->notifyAdmins(
                "{$pendingOrders} order(s) awaiting Super Admin audit",
                'Open the audit queue to approve and reserve stock.',
                '/admin/orders?audit_queue=1',
                'orders',
                'warning',
            );
            $count++;
        }

        $pendingPayments = Payment::query()->where('status', Payment::STATUS_PENDING)->count();
        if ($pendingPayments > 0) {
            $this->notifyAdmins(
                "{$pendingPayments} payment(s) pending verification",
                'Verify collections to update credit and commissions.',
                '/admin/payments?status=pending',
                'payments',
                'info',
            );
            $count++;
        }

        $pendingReturns = ProductReturn::query()->where('status', ProductReturn::STATUS_PENDING)->count();
        if ($pendingReturns > 0) {
            $this->notifyAdmins(
                "{$pendingReturns} return(s) awaiting review",
                'Approve to issue credit and optional restock.',
                '/admin/returns?status=pending',
                'returns',
                'warning',
            );
            $count++;
        }

        $pendingWarranty = ProductReturn::query()
            ->where('status', ProductReturn::STATUS_PENDING)
            ->where('reason_type', ProductReturn::REASON_WARRANTY)
            ->count();
        if ($pendingWarranty > 0) {
            $this->notifyAdmins(
                "{$pendingWarranty} warranty claim(s) need review before expiry",
                'Check Returns & warranty for claims still inside the product warranty window.',
                '/admin/returns?kind=warranty&status=pending',
                'returns',
                'warning',
            );
            $count++;
        }

        $expiringSoon = ProductReturn::query()
            ->with(['items.product', 'order'])
            ->whereIn('status', [ProductReturn::STATUS_PENDING, ProductReturn::STATUS_APPROVED])
            ->where('reason_type', ProductReturn::REASON_WARRANTY)
            ->latest('id')
            ->limit(80)
            ->get()
            ->sum(function (ProductReturn $return) {
                $start = $return->order?->submitted_at;
                foreach ($return->items as $item) {
                    $expiry = Warranty::expiresAt($item->product?->warranty, $start);
                    if ($expiry && $expiry->between(now(), now()->addDays(30))) {
                        return 1;
                    }
                }

                return 0;
            });
        if ($expiringSoon > 0) {
            $this->notifyAdmins(
                "{$expiringSoon} warranty claim(s) expire within 30 days",
                'Review replacement or repair before coverage ends.',
                '/admin/returns?kind=warranty',
                'returns',
                'warning',
            );
            $count++;
        }

        $this->notifyAdmins(
            'Phase 9 analytics & reports are live',
            'Dashboard KPIs, report packs and this notification centre now read from live data.',
            '/admin/analytics',
            'system',
            'info',
        );
        $count++;

        return $count;
    }
}
