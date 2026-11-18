<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class CollectionSummaryService
{
    /**
     * Shops with open baki, each with order-level remaining dues for easy collection.
     *
     * Shop "baki" is the ledger outstanding (open invoice balances − unapplied credit).
     * Order "due" is collectible now (invoice balance − pending field collections).
     * Delivered orders still awaiting an invoice are included so nothing is missed.
     *
     * @return Collection<int, array{shop:Shop,person:string,baki:float,orders:Collection<int,array{order:Order,due:float,remaining:float,pending:float,can_collect:bool}>,order_count:int,collectable:float,orders_due:float}>
     */
    public function openDuesByShop(User $viewer, ?string $search = null): Collection
    {
        $shops = $this->shopsFor($viewer)
            ->when($search, function ($q, $search) {
                $term = '%'.$search.'%';
                $q->where(function ($inner) use ($term) {
                    $inner->where('name', 'like', $term)
                        ->orWhere('code', 'like', $term)
                        ->orWhere('owner_name', 'like', $term)
                        ->orWhere('phone', 'like', $term);
                });
            })
            ->orderByDesc('outstanding_balance')
            ->orderBy('name')
            ->get();

        if ($shops->isEmpty()) {
            return collect();
        }

        $ordersByShop = Order::query()
            ->with(['invoice', 'advanceInvoice', 'shop', 'delivery'])
            ->whereIn('shop_id', $shops->pluck('id'))
            ->whereNotIn('status', [Order::STATUS_REJECTED, Order::STATUS_CANCELLED, Order::STATUS_DRAFT])
            ->latest('id')
            ->get()
            ->map(fn (Order $order) => $this->orderDueRow($order))
            ->filter()
            ->groupBy(fn (array $row) => $row['order']->shop_id);

        return $shops
            ->map(function (Shop $shop) use ($ordersByShop) {
                $orders = ($ordersByShop->get($shop->id) ?? collect())->values();
                $ordersDue = round((float) $orders->sum('due'), 2);
                $ledger = round((float) $shop->outstanding_balance, 2);

                return [
                    'shop' => $shop,
                    'person' => $shop->owner_name ?: $shop->name,
                    // Prefer ledger; if delivered-not-yet-invoiced, fall back to open order dues.
                    'baki' => round(max($ledger, $ordersDue), 2),
                    'orders' => $orders,
                    'order_count' => $orders->count(),
                    'collectable' => round((float) $orders->where('can_collect', true)->sum('due'), 2),
                    'orders_due' => $ordersDue,
                ];
            })
            ->filter(fn (array $row) => $row['baki'] > 0.009 || $row['order_count'] > 0)
            ->sortByDesc('baki')
            ->values();
    }

    /**
     * @return array{shop:Shop,person:string,baki:float,orders:Collection<int,array{order:Order,due:float,remaining:float,pending:float,can_collect:bool}>,order_count:int,collectable:float,orders_due:float}|null
     */
    public function openDuesForShop(User $viewer, Shop $shop): ?array
    {
        $shop = $this->shopsFor($viewer)->whereKey($shop->id)->first();
        if (! $shop) {
            return null;
        }

        $orders = Order::query()
            ->with(['invoice', 'advanceInvoice', 'shop', 'delivery'])
            ->where('shop_id', $shop->id)
            ->whereNotIn('status', [Order::STATUS_REJECTED, Order::STATUS_CANCELLED, Order::STATUS_DRAFT])
            ->latest('id')
            ->get()
            ->map(fn (Order $order) => $this->orderDueRow($order))
            ->filter()
            ->values();

        $ordersDue = round((float) $orders->sum('due'), 2);
        $ledger = round((float) $shop->outstanding_balance, 2);

        return [
            'shop' => $shop,
            'person' => $shop->owner_name ?: $shop->name,
            'baki' => round(max($ledger, $ordersDue), 2),
            'orders' => $orders,
            'order_count' => $orders->count(),
            'collectable' => round((float) $orders->where('can_collect', true)->sum('due'), 2),
            'orders_due' => $ordersDue,
        ];
    }

    /**
     * @return array{order:Order,due:float,remaining:float,pending:float,can_collect:bool}|null
     */
    private function orderDueRow(Order $order): ?array
    {
        $settle = $order->settlement();
        $due = round((float) $settle['baki'], 2);
        $remaining = round((float) $settle['remaining_due'], 2);
        $pending = round((float) $settle['pending_sales'], 2);

        if ($due <= 0.009 && $remaining <= 0.009) {
            return null;
        }

        // Show rows that still have ledger remaining (e.g. pending office confirm) or collectable baki.
        if ($due <= 0.009 && $pending <= 0.009) {
            return null;
        }

        return [
            'order' => $order,
            'due' => $due > 0.009 ? $due : 0.0,
            'remaining' => $remaining,
            'pending' => $pending,
            'can_collect' => $due > 0.009 && $order->canCollectNow(),
        ];
    }

    /**
     * Money received from each shop / owner.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function byShop(User $viewer, ?string $search = null): Collection
    {
        $shops = $this->shopsFor($viewer)
            ->when($search, function ($q, $search) {
                $term = '%'.$search.'%';
                $q->where(function ($inner) use ($term) {
                    $inner->where('name', 'like', $term)
                        ->orWhere('code', 'like', $term)
                        ->orWhere('owner_name', 'like', $term);
                });
            })
            ->orderBy('name')
            ->get();

        $shopIds = $shops->pluck('id');
        if ($shopIds->isEmpty()) {
            return collect();
        }

        $verified = Payment::STATUS_VERIFIED;
        $pending = Payment::STATUS_PENDING;
        $viewerId = (int) $viewer->id;

        $totals = Payment::query()
            ->whereIn('shop_id', $shopIds)
            ->whereIn('status', [$verified, $pending])
            ->selectRaw("
                shop_id,
                SUM(CASE WHEN status = '{$verified}' THEN amount ELSE 0 END) as received,
                SUM(CASE WHEN status = '{$pending}' THEN amount ELSE 0 END) as pending,
                SUM(CASE WHEN status = '{$verified}' AND created_by = {$viewerId} THEN amount ELSE 0 END) as you_collected,
                COUNT(*) as receipts,
                MAX(COALESCE(verified_at, paid_at, created_at)) as last_at
            ")
            ->groupBy('shop_id')
            ->get()
            ->keyBy('shop_id');

        return $shops
            ->map(function (Shop $shop) use ($totals) {
                $row = $totals->get($shop->id);

                return [
                    'shop' => $shop,
                    'person' => $shop->owner_name ?: $shop->name,
                    'received' => round((float) ($row->received ?? 0), 2),
                    'pending' => round((float) ($row->pending ?? 0), 2),
                    'you_collected' => round((float) ($row->you_collected ?? 0), 2),
                    'baki' => round((float) $shop->outstanding_balance, 2),
                    'receipts' => (int) ($row->receipts ?? 0),
                    'last_at' => ! empty($row?->last_at) ? Carbon::parse($row->last_at) : null,
                ];
            })
            ->filter(fn (array $row) => $row['received'] > 0.009 || $row['pending'] > 0.009 || $row['baki'] > 0.009)
            ->sortByDesc('received')
            ->values();
    }

    /**
     * @return Collection<int, Payment>
     */
    public function receipts(User $viewer, int $limit = 80): Collection
    {
        $shopIds = $this->shopsFor($viewer)->pluck('id');
        if ($shopIds->isEmpty()) {
            return collect();
        }

        return Payment::query()
            ->with(['shop', 'creator', 'invoice'])
            ->whereIn('shop_id', $shopIds)
            ->whereIn('status', [Payment::STATUS_VERIFIED, Payment::STATUS_PENDING])
            ->latest('paid_at')
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    private function shopsFor(User $viewer)
    {
        if ($viewer->portal === User::PORTAL_SALESMAN) {
            $orderShopIds = Order::query()
                ->where('salesman_id', $viewer->id)
                ->select('shop_id');
            $paymentShopIds = Payment::query()
                ->where('created_by', $viewer->id)
                ->select('shop_id');

            return Shop::query()
                ->where('status', Shop::STATUS_ACTIVE)
                ->where(function ($q) use ($viewer, $orderShopIds, $paymentShopIds) {
                    $q->where('assigned_salesman_id', $viewer->id)
                        ->orWhereIn('id', $orderShopIds)
                        ->orWhereIn('id', $paymentShopIds);
                });
        }

        return Shop::query()->visibleTo($viewer);
    }
}
