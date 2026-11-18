<?php

namespace App\Services;

use App\Models\Commission;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\Shop;
use App\Models\ShopVisit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function __construct(
        private AuditLogger $auditLogger,
        private InventoryService $inventory,
        private FulfilmentService $fulfilment,
        private InvoiceService $invoices,
        private CreditService $credit,
    ) {}

    /**
     * @param  list<array{product_id:int, quantity:int}>  $lines
     */
    public function collectFromSalesman(
        User $salesman,
        Shop $shop,
        array $lines,
        ?ShopVisit $visit = null,
        ?string $notes = null,
        float $discount = 0,
    ): Order {
        // Field officers may collect an order on behalf of any active shop.
        // Credit and the salesman who took the order are tracked on the order itself.
        if ($shop->status !== Shop::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'shop_id' => $shop->name.' is not active and cannot take orders.',
            ]);
        }

        return $this->submitOrder(
            shop: $shop,
            lines: $lines,
            actor: $salesman,
            source: Order::SOURCE_SALESMAN,
            salesmanId: $salesman->id,
            visit: $visit,
            notes: $notes,
            discount: $discount,
        );
    }

    /**
     * Record cash (or transfer) collected at the shop. Does not change order status —
     * collection is allowed at any stage, and leftover stays as baki.
     */
    public function recordFieldCollection(
        Order $order,
        User $salesman,
        float $paidNow,
        string $method = Payment::METHOD_CASH,
        ?string $reference = null,
    ): Order {
        return $this->collectAnytime($order, $salesman, $paidNow, $method, $reference);
    }

    public function collectAnytime(
        Order $order,
        User $actor,
        float $amount,
        string $method = Payment::METHOD_CASH,
        ?string $reference = null,
    ): Order {
        if (in_array($order->status, [Order::STATUS_REJECTED, Order::STATUS_CANCELLED], true)) {
            throw ValidationException::withMessages([
                'amount' => 'Cannot collect on a cancelled or rejected order.',
            ]);
        }

        $amount = round($amount, 2);
        if ($amount <= 0) {
            return $order;
        }

        $due = $order->fresh(['invoice', 'advanceInvoice'])->collectibleBalance();
        if ($amount > $due + 0.01) {
            throw ValidationException::withMessages([
                'amount' => 'Amount cannot exceed the remaining baki (৳ '.number_format($due, 2).').',
            ]);
        }

        if (! $order->invoice_id) {
            $this->invoices->createFromOrder($order->fresh('items'), $actor);
            $order->refresh()->load('invoice');
        }

        app(\App\Services\PaymentService::class)->collectAgainstInvoice(
            $order->invoice,
            $amount,
            $actor,
            $method,
            $reference,
            'Collected against order '.$order->number,
            applyNow: false,
        );

        return $order->fresh(['invoice', 'advanceInvoice', 'shop']);
    }

    public function deliver(Order $order, User $actor, ?string $notes = null): Order
    {
        return $this->fulfilment->deliverNow($order, $actor, $notes);
    }

    /**
     * @param  list<array{product_id:int, quantity:int}>  $lines
     */
    public function placeFromShopPortal(User $shopUser, Shop $shop, array $lines, ?string $notes = null, ?int $salesmanId = null): Order
    {
        if (! $shop->users()->where('users.id', $shopUser->id)->exists()) {
            throw ValidationException::withMessages([
                'shop' => 'You are not linked to this shop.',
            ]);
        }

        return $this->submitOrder(
            shop: $shop,
            lines: $lines,
            actor: $shopUser,
            source: Order::SOURCE_SHOP_PORTAL,
            salesmanId: $salesmanId ?: $shop->assigned_salesman_id,
            notes: $notes,
        );
    }

    /**
     * Partners may change quantities / notes only while the order awaits Super Admin audit.
     *
     * @param  list<array{product_id:int, quantity:int}>  $lines
     */
    public function updatePendingFromShopPortal(User $shopUser, Shop $shop, Order $order, array $lines, ?string $notes = null): Order
    {
        if (! $shop->users()->where('users.id', $shopUser->id)->exists()) {
            throw ValidationException::withMessages([
                'shop' => 'You are not linked to this shop.',
            ]);
        }

        if ($order->shop_id !== $shop->id) {
            throw ValidationException::withMessages([
                'order' => 'This order does not belong to your shop.',
            ]);
        }

        if (! $order->canPartnerEdit()) {
            throw ValidationException::withMessages([
                'order' => 'Only orders waiting for Super Admin approval can be edited.',
            ]);
        }

        if ($shop->status !== Shop::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'shop' => 'Orders can only be edited while the shop is active.',
            ]);
        }

        $lines = collect($lines)
            ->filter(fn ($line) => (int) ($line['quantity'] ?? 0) > 0)
            ->values();

        if ($lines->isEmpty()) {
            throw ValidationException::withMessages([
                'items' => 'Add at least one product with quantity.',
            ]);
        }

        return DB::transaction(function () use ($shop, $order, $lines, $shopUser, $notes) {
            $order = Order::query()->lockForUpdate()->with('items')->findOrFail($order->id);

            if (! $order->canPartnerEdit() || $order->shop_id !== $shop->id) {
                throw ValidationException::withMessages([
                    'order' => 'Only orders waiting for Super Admin approval can be edited.',
                ]);
            }

            $shop->loadMissing('priceGroup');
            $old = [
                'total' => (float) $order->total,
                'item_count' => (int) $order->item_count,
                'notes' => $order->notes,
            ];

            $order->items()->delete();

            $subtotal = 0;
            $itemCount = 0;

            foreach ($lines as $line) {
                $product = Product::query()->findOrFail($line['product_id']);

                if ($product->status !== Product::STATUS_ACTIVE || ! $product->is_published) {
                    throw ValidationException::withMessages([
                        'items' => "Product {$product->sku} is not available.",
                    ]);
                }

                $qty = (int) $line['quantity'];
                [$original, $unit] = $this->resolveLinePricing($product, $shop, $line, Order::SOURCE_SHOP_PORTAL);
                $lineTotal = round($unit * $qty, 2);

                OrderItem::query()->create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_sku' => $product->sku,
                    'quantity' => $qty,
                    'original_unit_price' => $original,
                    'unit_price' => $unit,
                    'line_total' => $lineTotal,
                ]);

                $subtotal = round($subtotal + $lineTotal, 2);
                $itemCount += $qty;
            }

            $order->update([
                'notes' => $notes,
                'subtotal' => $subtotal,
                'discount_total' => 0,
                'total' => $subtotal,
                'item_count' => $itemCount,
                'status' => Order::STATUS_PENDING_AUDIT,
                'submitted_at' => now(),
            ]);

            $this->recordHistory($order, Order::STATUS_PENDING_AUDIT, Order::STATUS_PENDING_AUDIT, 'edited_before_audit', $notes, [
                'old_total' => $old['total'],
                'new_total' => $subtotal,
                'old_item_count' => $old['item_count'],
                'new_item_count' => $itemCount,
            ], $shopUser);

            $this->auditLogger->log(
                'orders',
                'edited_before_audit',
                "Order {$order->number} edited by shop partner before audit",
                $order,
                $old,
                ['total' => $subtotal, 'item_count' => $itemCount, 'notes' => $notes],
                $shopUser,
            );

            return $order->fresh(['items', 'shop', 'salesman']);
        });
    }

    /**
     * @param  list<array{product_id:int, quantity:int}>  $lines
     */
    public function createFromAdmin(User $admin, Shop $shop, array $lines, ?string $notes = null, ?int $salesmanId = null, float $discount = 0): Order
    {
        return $this->submitOrder(
            shop: $shop,
            lines: $lines,
            actor: $admin,
            source: Order::SOURCE_ADMIN,
            salesmanId: $salesmanId ?: $shop->assigned_salesman_id,
            notes: $notes,
            discount: $discount,
        );
    }

    public function applyDiscount(Order $order, float $amount, User $actor): Order
    {
        $amount = round(max(0, $amount), 2);

        if (! $order->isPendingAudit() || $order->stock_reserved || $order->advance_required || $order->hasRecordedPayments()) {
            throw ValidationException::withMessages([
                'discount_total' => 'The total can only be reduced before approval, while no payment has been recorded.',
            ]);
        }

        $subtotal = round((float) $order->subtotal, 2);
        if ($amount > $subtotal + 0.01) {
            throw ValidationException::withMessages([
                'discount_total' => 'Discount cannot exceed the order subtotal.',
            ]);
        }

        $total = max(0, round($subtotal - $amount, 2));

        return DB::transaction(function () use ($order, $actor, $amount, $total) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $from = (float) $order->total;
            $order->update([
                'discount_total' => $amount,
                'total' => $total,
            ]);

            $this->recordHistory($order, $order->status, $order->status, 'discount_applied', null, [
                'old_total' => $from,
                'discount' => $amount,
                'new_total' => $total,
            ], $actor);

            $this->auditLogger->log(
                'orders',
                'discounted',
                "Order {$order->number} total reduced by ৳ ".number_format($amount, 2),
                $order,
                ['total' => $from],
                ['discount_total' => $amount, 'total' => $total],
                $actor,
            );

            return $order->fresh(['items', 'shop', 'salesman']);
        });
    }


    public function requestAdvance(Order $order, User $actor, float $amount, ?string $notes = null): Order
    {
        if (! $order->isPendingAudit()) {
            throw ValidationException::withMessages([
                'order' => 'Advance can only be requested on orders pending audit.',
            ]);
        }

        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['advance_amount' => 'Enter a valid advance amount.']);
        }
        if ($amount > (float) $order->total + 0.01) {
            throw ValidationException::withMessages(['advance_amount' => 'Advance cannot exceed order total.']);
        }

        return DB::transaction(function () use ($order, $actor, $amount, $notes) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if (! $order->isPendingAudit()) {
                throw ValidationException::withMessages([
                    'order' => 'Advance can only be requested on orders pending audit.',
                ]);
            }

            $invoice = $this->invoices->createAdvanceInvoice($order, $amount, $actor);
            $from = $order->status;

            $order->update([
                'status' => Order::STATUS_AWAITING_ADVANCE,
                'advance_required' => true,
                'advance_amount' => $amount,
                'advance_invoice_id' => $invoice->id,
                'advance_requested_at' => now(),
                'advance_paid_at' => null,
                'audit_notes' => $notes,
                'audited_by' => $actor->id,
            ]);

            $this->recordHistory($order, $from, Order::STATUS_AWAITING_ADVANCE, 'advance_requested', $notes, [
                'advance_amount' => $amount,
                'advance_invoice_id' => $invoice->id,
            ], $actor);

            $this->auditLogger->log(
                'orders',
                'advance_requested',
                'Advance of ৳ '.number_format($amount, 2)." requested for {$order->number}",
                $order,
                ['status' => $from],
                ['status' => Order::STATUS_AWAITING_ADVANCE, 'advance_amount' => $amount],
                $actor,
            );

            return $order->fresh(['items.product', 'shop', 'salesman', 'advanceInvoice', 'statusHistories.user']);
        });
    }

    public function refreshAdvanceStatus(Order $order): Order
    {
        $order->loadMissing('advanceInvoice');

        if (! $order->advance_required || ! $order->advance_invoice_id) {
            return $order;
        }

        // Re-read invoice balances after payment apply.
        $order->unsetRelation('advanceInvoice');
        $order->load('advanceInvoice');

        if (! $order->isAdvancePaid()) {
            return $order->fresh(['advanceInvoice']);
        }

        if (! $order->advance_paid_at) {
            $order->update(['advance_paid_at' => now()]);

            $remaining = max(0, round((float) $order->total - (float) ($order->advance_amount ?: 0), 2));
            $note = 'Advance payment received — remaining ৳ '.number_format($remaining, 2)
                .' still due on final invoice / delivery. Order is ready to approve.';

            $this->recordHistory(
                $order,
                $order->status,
                $order->status,
                'advance_paid',
                $note,
                [
                    'advance_amount' => (float) $order->advance_amount,
                    'remaining_due' => $remaining,
                    'order_total' => (float) $order->total,
                    'invoice_id' => $order->advance_invoice_id,
                ],
                null,
            );

            $this->auditLogger->log(
                'orders',
                'advance_paid',
                "Advance paid for {$order->number} — remaining ৳ ".number_format($remaining, 2).' acknowledged',
                $order,
                null,
                [
                    'advance_amount' => (float) $order->advance_amount,
                    'remaining_due' => $remaining,
                ],
                null,
            );
        }

        return $order->fresh(['advanceInvoice']);
    }

    /**
     * Record (and auto-apply) the remaining advance for an order in one step.
     */
    public function collectAdvance(
        Order $order,
        User $actor,
        float $amount,
        string $method = \App\Models\Payment::METHOD_CASH,
        ?string $reference = null,
    ): Order {
        if (! $order->isAwaitingAdvance() || ! $order->advance_invoice_id) {
            throw ValidationException::withMessages([
                'order' => 'This order is not waiting for an advance payment.',
            ]);
        }

        $order->loadMissing('advanceInvoice');
        $invoice = $order->advanceInvoice;
        if (! $invoice) {
            throw ValidationException::withMessages(['advance' => 'Advance invoice missing.']);
        }

        $balance = (float) $invoice->balance;
        if ($balance <= 0.009) {
            return $this->refreshAdvanceStatus($order);
        }

        $amount = round($amount, 2);
        if ($amount <= 0) {
            $amount = $balance;
        }
        if ($amount > $balance + 0.01) {
            throw ValidationException::withMessages([
                'amount' => 'Amount exceeds advance balance (৳ '.number_format($balance, 2).').',
            ]);
        }

        app(\App\Services\PaymentService::class)->record([
            'shop_id' => $order->shop_id,
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'method' => $method,
            'reference' => $reference,
            'notes' => 'Advance for order '.$order->number,
        ], $actor);

        return $this->refreshAdvanceStatus($order->fresh(['advanceInvoice']));
    }

    public function approve(Order $order, User $actor, ?string $notes = null, bool $creditOverride = false): Order
    {
        if (! $order->canApproveNow()) {
            if ($order->isAwaitingAdvance() && ! $order->isAdvancePaid()) {
                throw ValidationException::withMessages([
                    'advance' => 'Advance payment is still due. Collect and verify the advance before approving.',
                ]);
            }

            throw ValidationException::withMessages([
                'order' => 'Only orders pending audit (or with advance paid) can be approved.',
            ]);
        }

        return DB::transaction(function () use ($order, $actor, $notes, $creditOverride) {
            $order = Order::query()->lockForUpdate()->with(['items', 'shop', 'advanceInvoice'])->findOrFail($order->id);

            if (! $order->canApproveNow()) {
                throw ValidationException::withMessages([
                    'order' => 'Only orders pending audit (or with advance paid) can be approved.',
                ]);
            }
            $shop = Shop::query()->lockForUpdate()->findOrFail($order->shop_id);
            $this->credit->recalculateOutstanding($shop);
            $order->setRelation('shop', $shop->refresh());
            $creditCheck = $this->credit->creditCheck($order);
            $availableCredit = $creditCheck['available'];
            $overrideUsed = $this->credit->assertCanOrder($order, $actor, $creditOverride);
            $warehouse = Warehouse::defaultWarehouse();

            if (! $warehouse) {
                throw ValidationException::withMessages([
                    'warehouse' => 'Configure at least one active warehouse before approving orders.',
                ]);
            }

            $stockIssues = [];

            foreach ($order->items as $item) {
                if (! $item->product_id) {
                    $stockIssues[] = "{$item->product_sku}: product missing";

                    continue;
                }

                $product = Product::query()->find($item->product_id);

                if (! $product) {
                    $stockIssues[] = "{$item->product_sku}: product missing";

                    continue;
                }

                $whStock = $this->inventory->stockFor($warehouse, $product);
                $available = $whStock->available();
                $item->update(['available_at_audit' => $available]);

                if ($available < $item->quantity) {
                    $stockIssues[] = "{$item->product_sku}: need {$item->quantity}, available {$available} at {$warehouse->code}";
                }
            }

            if ($stockIssues !== []) {
                throw ValidationException::withMessages([
                    'stock' => 'Insufficient stock: '.implode('; ', $stockIssues),
                ]);
            }

            foreach ($order->items as $item) {
                $product = Product::query()->findOrFail($item->product_id);
                $this->inventory->reserve($warehouse, $product, (int) $item->quantity, $order, $actor);
                $item->update(['reserved_quantity' => $item->quantity]);
            }

            $from = $order->status;

            $order->update([
                'status' => Order::STATUS_APPROVED,
                'warehouse_id' => $warehouse->id,
                'audited_by' => $actor->id,
                'audited_at' => now(),
                'stock_reserved_at' => now(),
                'stock_reserved' => true,
                'audit_notes' => $notes,
                'credit_available_at_audit' => $availableCredit,
                'credit_override' => $overrideUsed,
                'rejection_reason' => null,
            ]);

            $this->fulfilment->createForApprovedOrder($order, $warehouse, $actor);

            $this->recordHistory($order, $from, Order::STATUS_APPROVED, 'approved_reserved', $notes, [
                'credit_available' => $availableCredit,
                'credit_needed' => $creditCheck['needed'],
                'credit_override' => $overrideUsed,
                'total' => (float) $order->total,
                'warehouse_id' => $warehouse->id,
                'advance_amount' => (float) ($order->advance_amount ?: 0),
            ], $actor);

            $this->auditLogger->log(
                'orders',
                'approved',
                "Order {$order->number} approved and stock reserved at {$warehouse->code}",
                $order,
                ['status' => $from],
                ['status' => Order::STATUS_APPROVED, 'stock_reserved' => true, 'credit_override' => $overrideUsed],
                $actor,
            );

            $fresh = $order->fresh(['items.product', 'shop', 'salesman', 'auditor', 'fulfilment', 'warehouse', 'statusHistories.user']);

            DB::afterCommit(function () use ($fresh) {
                try {
                    app(AppNotificationService::class)->orderApproved($fresh);
                } catch (\Throwable) {
                    // non-blocking
                }
            });

            return $fresh;
        });
    }

    public function reject(Order $order, User $actor, string $reason): Order
    {
        if (! in_array($order->status, [Order::STATUS_PENDING_AUDIT, Order::STATUS_AWAITING_ADVANCE], true)) {
            throw ValidationException::withMessages([
                'order' => 'Only orders pending audit or awaiting advance can be rejected.',
            ]);
        }

        return DB::transaction(function () use ($order, $actor, $reason) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            if (! in_array($order->status, [Order::STATUS_PENDING_AUDIT, Order::STATUS_AWAITING_ADVANCE], true)) {
                throw ValidationException::withMessages([
                    'order' => 'Only orders pending audit or awaiting advance can be rejected.',
                ]);
            }
            $from = $order->status;
            $credited = $this->releaseOrderMoney($order, $actor, 'rejected');

            $order->update([
                'status' => Order::STATUS_REJECTED,
                'audited_by' => $actor->id,
                'audited_at' => now(),
                'rejection_reason' => $reason,
                'audit_notes' => $reason,
                'credit_available_at_audit' => $order->shop?->availableCredit(),
                'stock_reserved' => false,
            ]);

            $this->recordHistory($order, $from, Order::STATUS_REJECTED, 'rejected', $reason, $credited > 0 ? ['credited_to_shop' => $credited] : null, $actor);

            $this->auditLogger->log(
                'orders',
                'rejected',
                "Order {$order->number} rejected",
                $order,
                ['status' => $from],
                ['status' => Order::STATUS_REJECTED, 'reason' => $reason],
                $actor,
            );

            return $order->fresh(['items', 'shop', 'salesman', 'auditor', 'statusHistories.user']);
        });
    }

    public function deleteBeforeApproval(Order $order, User $actor): void
    {
        if (! $order->canDeleteBeforeApproval()) {
            throw ValidationException::withMessages([
                'order' => 'Only orders waiting for Super Admin audit can be deleted.',
            ]);
        }

        DB::transaction(function () use ($order, $actor) {
            $order = Order::query()->lockForUpdate()->with(['items', 'shop'])->findOrFail($order->id);

            if (! $order->canDeleteBeforeApproval()) {
                throw ValidationException::withMessages([
                    'order' => 'Only orders waiting for Super Admin audit can be deleted.',
                ]);
            }

            if ($order->hasRecordedPayments()) {
                throw ValidationException::withMessages([
                    'order' => 'A payment is already recorded for this order, so it cannot be deleted. Reject or cancel it instead — the money becomes shop credit.',
                ]);
            }

            $this->releaseOrderMoney($order, $actor, 'deleted');

            $number = $order->number;
            $from = $order->status;

            $this->recordHistory($order, $from, $from, 'deleted_before_audit', 'Order deleted before approval', [
                'number' => $number,
                'total' => (float) $order->total,
            ], $actor);

            $this->auditLogger->log(
                'orders',
                'deleted',
                "Order {$number} deleted before approval",
                $order,
                ['status' => $from, 'total' => (float) $order->total],
                null,
                $actor,
            );

            $order->items()->delete();
            $order->delete();
        });
    }

    public function cancel(Order $order, User $actor, string $reason): Order
    {
        if (! in_array($order->status, [Order::STATUS_PENDING_AUDIT, Order::STATUS_AWAITING_ADVANCE, Order::STATUS_APPROVED], true)) {
            throw ValidationException::withMessages([
                'order' => 'Only pending or approved orders can be cancelled.',
            ]);
        }

        return DB::transaction(function () use ($order, $actor, $reason) {
            $order = Order::query()->lockForUpdate()->with(['items.product', 'warehouse'])->findOrFail($order->id);
            if (! in_array($order->status, [Order::STATUS_PENDING_AUDIT, Order::STATUS_AWAITING_ADVANCE, Order::STATUS_APPROVED], true)) {
                throw ValidationException::withMessages([
                    'order' => 'Only pending or approved orders can be cancelled.',
                ]);
            }
            $order->setRelation('fulfilment', \App\Models\OrderFulfilment::query()->where('order_id', $order->id)->lockForUpdate()->first());
            $from = $order->status;

            if ($order->fulfilment && ! in_array($order->fulfilment->status, [
                \App\Models\OrderFulfilment::STATUS_AWAITING_PICK,
            ], true)) {
                throw ValidationException::withMessages([
                    'order' => 'Cannot cancel after warehouse picking has started.',
                ]);
            }

            if ($order->stock_reserved) {
                $warehouse = $order->warehouse ?: Warehouse::defaultWarehouse();
                foreach ($order->items as $item) {
                    $qty = (int) $item->reserved_quantity;
                    if ($qty > 0 && $item->product_id && $warehouse) {
                        $this->inventory->release($warehouse, $item->product, $qty, $order, $actor);
                        $item->update(['reserved_quantity' => 0]);
                    }
                }
            }

            if ($order->fulfilment) {
                $order->fulfilment->delete();
            }

            $credited = $this->releaseOrderMoney($order, $actor, 'cancelled');

            $order->update([
                'status' => Order::STATUS_CANCELLED,
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
                'stock_reserved' => false,
                'stock_reserved_at' => null,
            ]);

            $this->recordHistory($order, $from, Order::STATUS_CANCELLED, 'cancelled', $reason, [
                'released_reservation' => $from === Order::STATUS_APPROVED,
                'credited_to_shop' => $credited,
            ], $actor);

            $this->auditLogger->log(
                'orders',
                'cancelled',
                "Order {$order->number} cancelled",
                $order,
                ['status' => $from],
                ['status' => Order::STATUS_CANCELLED, 'reason' => $reason],
                $actor,
            );

            return $order->fresh(['items.product', 'shop', 'salesman', 'statusHistories.user']);
        });
    }

    /**
     * Snapshot stock + credit readiness for the audit screen (no locks).
     *
     * @return array{credit_ok:bool, credit_available:float, credit_needed:float, credit_limit:float, credit_exposure:float, stock_ok:bool, lines:list<array>}
     */
    public function auditSnapshot(Order $order): array
    {
        $order->loadMissing(['items.product', 'shop']);
        $credit = $order->shop
            ? $this->credit->creditCheck($order)
            : ['ok' => false, 'available' => 0.0, 'needed' => (float) $order->total, 'limit' => 0.0, 'exposure' => 0.0];
        $lines = [];
        $stockOk = true;
        $approvalStock = $this->approvalStock($order);

        foreach ($order->items as $item) {
            $available = $approvalStock[$item->product_id] ?? 0;
            $ok = $available >= $item->quantity;
            if (! $ok) {
                $stockOk = false;
            }
            $lines[] = [
                'sku' => $item->product_sku,
                'name' => $item->product_name,
                'qty' => $item->quantity,
                'available' => $available,
                'ok' => $ok,
            ];
        }

        return [
            'credit_ok' => $credit['ok'],
            'credit_available' => (float) $credit['available'],
            'credit_needed' => (float) $credit['needed'],
            'credit_limit' => (float) $credit['limit'],
            'credit_exposure' => (float) $credit['exposure'],
            'stock_ok' => $stockOk,
            'lines' => $lines,
        ];
    }

    /**
     * Stock approval can actually reserve: free quantity in the default warehouse, per product.
     *
     * @return array<int, int>
     */
    public function approvalStock(Order $order): array
    {
        $warehouse = Warehouse::defaultWarehouse();
        $productIds = $order->items->pluck('product_id')->filter()->unique()->values();
        if (! $warehouse || $productIds->isEmpty()) {
            return [];
        }

        return \App\Models\WarehouseStock::query()
            ->where('warehouse_id', $warehouse->id)
            ->whereIn('product_id', $productIds)
            ->get()
            ->mapWithKeys(fn ($s) => [(int) $s->product_id => max(0, (int) $s->qty_on_hand - (int) $s->qty_reserved)])
            ->all();
    }

    /**
     * An order closed without fulfilment must not leave money behind: its invoices are voided, any
     * payment against them becomes unallocated shop credit, and unpaid commissions on it are rejected.
     *
     * @return float amount of verified money moved to shop credit
     */
    private function releaseOrderMoney(Order $order, User $actor, string $why): float
    {
        $invoiceIds = array_values(array_filter([$order->advance_invoice_id, $order->invoice_id]));
        if ($invoiceIds === []) {
            return 0.0;
        }

        $credited = 0.0;
        $invoices = Invoice::query()->whereIn('id', $invoiceIds)->lockForUpdate()->get();
        foreach ($invoices as $invoice) {
            if ($invoice->status === Invoice::STATUS_VOID) {
                continue;
            }

            $payments = Payment::query()
                ->where('invoice_id', $invoice->id)
                ->whereIn('status', [Payment::STATUS_PENDING, Payment::STATUS_VERIFIED])
                ->lockForUpdate()
                ->get();

            foreach ($payments as $payment) {
                if ($payment->status === Payment::STATUS_VERIFIED) {
                    $credited += (float) $payment->amount;
                }
                $payment->update([
                    'invoice_id' => null,
                    'notes' => trim(($payment->notes ? $payment->notes."\n" : '')."Moved to shop credit — order {$order->number} {$why}."),
                ]);
            }

            Commission::query()
                ->whereIn('payment_id', $payments->pluck('id'))
                ->whereIn('status', [Commission::STATUS_ACCRUED, Commission::STATUS_APPROVED])
                ->get()
                ->each(fn (Commission $commission) => app(CommissionService::class)->reject($commission, "Order {$order->number} {$why}", $actor));

            $invoice->update(['status' => Invoice::STATUS_VOID, 'paid_amount' => 0, 'balance' => 0]);
        }

        $this->credit->recalculateOutstanding(Shop::query()->findOrFail($order->shop_id));

        if ($credited > 0) {
            $this->auditLogger->log(
                'credit',
                'order_money_released',
                "৳ ".number_format($credited, 2)." paid on {$order->number} moved to shop credit ({$why})",
                $order,
                null,
                ['credited' => round($credited, 2), 'invoices' => $invoiceIds],
                $actor,
            );
        }

        return round($credited, 2);
    }

    public function nextNumber(): string
    {
        return \App\Support\DocumentNumber::next(Order::class, 'ORD-'.now()->format('ymd').'-', 4);
    }

    /**
     * @param  list<array{product_id:int, quantity:int, unit_price?:float|int|string}>  $lines
     */
    private function submitOrder(
        Shop $shop,
        array $lines,
        User $actor,
        string $source,
        ?int $salesmanId = null,
        ?ShopVisit $visit = null,
        ?string $notes = null,
        float $discount = 0,
    ): Order {
        if ($shop->status !== Shop::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'shop_id' => 'Orders can only be placed for active shops.',
            ]);
        }

        $lines = collect($lines)
            ->filter(fn ($line) => (int) ($line['quantity'] ?? 0) > 0)
            ->values();

        if ($lines->isEmpty()) {
            throw ValidationException::withMessages([
                'items' => 'Add at least one product with quantity.',
            ]);
        }

        return DB::transaction(function () use ($shop, $lines, $actor, $source, $salesmanId, $visit, $notes, $discount) {
            $shop->loadMissing('priceGroup');

            if ($visit) {
                $visit = ShopVisit::query()->lockForUpdate()->findOrFail($visit->id);
                if (! $visit->isOpen() || $visit->order_id) {
                    throw ValidationException::withMessages([
                        'visit' => 'An order was already sent from this visit. Check in again to take a new order.',
                    ]);
                }
            }

            $order = Order::query()->create([
                'number' => $this->nextNumber(),
                'shop_id' => $shop->id,
                'salesman_id' => $salesmanId,
                'visit_id' => $visit?->id,
                'source' => $source,
                'status' => Order::STATUS_PENDING_AUDIT,
                'notes' => $notes,
                'submitted_at' => now(),
                'created_by' => $actor->id,
            ]);

            $subtotal = 0;
            $itemCount = 0;
            $warehouse = Warehouse::defaultWarehouse();
            $stockIssues = [];

            foreach ($lines as $line) {
                $product = Product::query()->with('offers')->findOrFail($line['product_id']);

                if ($product->status !== Product::STATUS_ACTIVE || ! $product->is_published) {
                    throw ValidationException::withMessages([
                        'items' => "Product {$product->sku} is not available.",
                    ]);
                }

                $qty = (int) $line['quantity'];

                if ($warehouse) {
                    $available = $this->inventory->stockFor($warehouse, $product)->available();
                    if ($available < $qty) {
                        $stockIssues[] = "{$product->sku}: need {$qty}, available {$available}";
                    }
                } elseif ($product->availableStock() < $qty) {
                    $stockIssues[] = "{$product->sku}: need {$qty}, available {$product->availableStock()}";
                }

                [$original, $unit] = $this->resolveLinePricing($product, $shop, $line, $source);
                $lineTotal = round($unit * $qty, 2);

                OrderItem::query()->create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_sku' => $product->sku,
                    'quantity' => $qty,
                    'original_unit_price' => $original,
                    'unit_price' => $unit,
                    'line_total' => $lineTotal,
                ]);

                $subtotal = round($subtotal + $lineTotal, 2);
                $itemCount += $qty;
            }

            if ($stockIssues !== []) {
                throw ValidationException::withMessages([
                    'stock' => 'Only in-stock products can be ordered: '.implode('; ', $stockIssues),
                ]);
            }

            $discount = round(max(0, $discount), 2);
            if ($discount > $subtotal + 0.01) {
                throw ValidationException::withMessages([
                    'discount_total' => 'Discount cannot exceed the order subtotal.',
                ]);
            }
            $total = max(0, round($subtotal - $discount, 2));

            $order->update([
                'subtotal' => $subtotal,
                'discount_total' => $discount,
                'total' => $total,
                'item_count' => $itemCount,
            ]);

            if ($visit) {
                $visit->update([
                    'order_id' => $order->id,
                    'outcome' => ShopVisit::OUTCOME_ORDER_TAKEN,
                ]);
            }

            $this->recordHistory($order, null, Order::STATUS_PENDING_AUDIT, 'submitted_for_audit', $notes, [
                'source' => $source,
                'total' => $total,
                'discount' => $discount,
            ], $actor);

            $this->auditLogger->log(
                'orders',
                'submitted_for_audit',
                "Order {$order->number} submitted for Super Admin audit",
                $order,
                null,
                ['shop_id' => $shop->id, 'total' => $total, 'discount' => $discount, 'items' => $itemCount, 'source' => $source],
                $actor,
            );

            $fresh = $order->fresh(['items', 'shop', 'salesman', 'visit']);

            DB::afterCommit(function () use ($fresh) {
                try {
                    app(AppNotificationService::class)->orderPendingAudit($fresh);
                } catch (\Throwable) {
                    // non-blocking
                }
            });

            return $fresh;
        });
    }

    /**
     * @param  array{product_id?:int, quantity?:int, unit_price?:float|int|string|null}  $line
     * @return array{0: float, 1: float}
     */
    private function resolveLinePricing(Product $product, Shop $shop, array $line, string $source): array
    {
        $original = round((float) $product->priceForGroup($shop->priceGroup), 2);
        if ($original <= 0) {
            throw ValidationException::withMessages([
                'items' => "Product {$product->sku} has no price yet. Ask the office to set it before ordering.",
            ]);
        }

        $canOverride = in_array($source, [Order::SOURCE_SALESMAN, Order::SOURCE_ADMIN], true);
        $requested = $line['unit_price'] ?? null;
        $unit = $original;

        if ($canOverride && $requested !== null && $requested !== '') {
            $unit = round((float) $requested, 2);
        }

        if ($unit <= 0) {
            throw ValidationException::withMessages([
                'items' => "Product {$product->sku} needs a selling price above zero.",
            ]);
        }

        $floor = round((float) ($product->minimum_selling_price ?? 0), 2);
        if ($canOverride && $floor > 0 && $unit + 0.001 < $floor) {
            throw ValidationException::withMessages([
                'items' => "{$product->sku}: selling price cannot be below ৳".number_format($floor, 2).'.',
            ]);
        }

        return [$original, $unit];
    }

    private function recordHistory(
        Order $order,
        ?string $from,
        string $to,
        string $event,
        ?string $notes,
        ?array $meta,
        ?User $actor,
    ): void {
        OrderStatusHistory::query()->create([
            'order_id' => $order->id,
            'from_status' => $from,
            'to_status' => $to,
            'event' => $event,
            'notes' => $notes,
            'meta' => $meta,
            'user_id' => $actor?->id,
        ]);
    }
}
