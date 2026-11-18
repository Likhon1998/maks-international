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

        $pendingByShop = $this->pendingCollectedByShop($shops->pluck('id'));

        return $shops
            ->map(function (Shop $shop) use ($ordersByShop, $pendingByShop) {
                $orders = ($ordersByShop->get($shop->id) ?? collect())->values();
                $ordersDue = round((float) $orders->sum('due'), 2);
                $pending = round((float) ($pendingByShop[$shop->id] ?? 0), 2);
                // Ledger ignores pending আদায় until office verifies — field UI must subtract it.
                $ledger = max(0, round((float) $shop->outstanding_balance - $pending, 2));

                return [
                    'shop' => $shop,
                    'person' => $shop->owner_name ?: $shop->name,
                    // Prefer effective ledger; if delivered-not-yet-invoiced, fall back to open order dues.
                    'baki' => round(max($ledger, $ordersDue), 2),
                    'pending' => $pending,
                    'orders' => $orders,
                    'order_count' => $orders->count(),
                    'collectable' => round((float) $orders->where('can_collect', true)->sum('due'), 2),
                    'orders_due' => $ordersDue,
                ];
            })
            ->filter(fn (array $row) => $row['baki'] > 0.009 || $row['order_count'] > 0 || $row['pending'] > 0.009)
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
        $pending = $this->pendingCollectedForShop((int) $shop->id);
        $ledger = max(0, round((float) $shop->outstanding_balance - $pending, 2));

        return [
            'shop' => $shop,
            'person' => $shop->owner_name ?: $shop->name,
            'baki' => round(max($ledger, $ordersDue), 2),
            'pending' => $pending,
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
     * When $from/$to are set, payment totals and receipt counts are limited to that
     * paid_at (fallback created_at) window. "Baki" stays the current open balance
     * (ledger outstanding − all pending collections) — there is no historical snapshot.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function byShop(User $viewer, ?string $search = null, ?Carbon $from = null, ?Carbon $to = null): Collection
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
        $scoped = $from !== null || $to !== null;

        $totalsQuery = Payment::query()
            ->whereIn('shop_id', $shopIds)
            ->whereIn('status', [$verified, $pending]);
        $this->applyPaymentPeriod($totalsQuery, $from, $to);

        $totals = $totalsQuery
            ->selectRaw("
                shop_id,
                SUM(CASE WHEN status = '{$verified}' THEN amount ELSE 0 END) as received,
                SUM(CASE WHEN status = '{$pending}' THEN amount ELSE 0 END) as pending,
                SUM(CASE WHEN created_by = {$viewerId} AND status IN ('{$verified}', '{$pending}') THEN amount ELSE 0 END) as you_collected,
                COUNT(*) as receipts,
                MAX(COALESCE(verified_at, paid_at, created_at)) as last_at
            ")
            ->groupBy('shop_id')
            ->get()
            ->keyBy('shop_id');

        // Effective baki always uses *all* pending (not only period pending).
        $pendingAll = $this->pendingCollectedByShop($shopIds);

        return $shops
            ->map(function (Shop $shop) use ($totals, $pendingAll, $scoped) {
                $row = $totals->get($shop->id);
                $periodPending = round((float) ($row->pending ?? 0), 2);
                $allPending = round((float) ($pendingAll[$shop->id] ?? 0), 2);
                $received = round((float) ($row->received ?? 0), 2);
                $youCollected = round((float) ($row->you_collected ?? 0), 2);
                $receipts = (int) ($row->receipts ?? 0);
                $baki = max(0, round((float) $shop->outstanding_balance - $allPending, 2));

                return [
                    'shop' => $shop,
                    'person' => $shop->owner_name ?: $shop->name,
                    'received' => $received,
                    'pending' => $periodPending,
                    'you_collected' => $youCollected,
                    'baki' => $baki,
                    'receipts' => $receipts,
                    'last_at' => ! empty($row?->last_at) ? Carbon::parse($row->last_at) : null,
                    'period_collected' => round($received + $periodPending, 2),
                ];
            })
            ->filter(function (array $row) use ($scoped) {
                if ($scoped) {
                    return $row['received'] > 0.009 || $row['pending'] > 0.009 || $row['you_collected'] > 0.009;
                }

                return $row['received'] > 0.009 || $row['pending'] > 0.009 || $row['baki'] > 0.009;
            })
            ->sortByDesc(fn (array $row) => $scoped ? $row['period_collected'] : $row['received'])
            ->values();
    }

    /**
     * Current open baki across the viewer's shops (ledger − all pending collections).
     */
    public function openBakiTotal(User $viewer): float
    {
        $shops = $this->shopsFor($viewer)->get(['id', 'outstanding_balance']);
        if ($shops->isEmpty()) {
            return 0.0;
        }

        $pending = $this->pendingCollectedByShop($shops->pluck('id'));

        return round((float) $shops->sum(function (Shop $shop) use ($pending) {
            return max(0, (float) $shop->outstanding_balance - (float) ($pending[$shop->id] ?? 0));
        }), 2);
    }

    /**
     * @return array{
     *     received:float,
     *     pending:float,
     *     you_collected:float,
     *     collected:float,
     *     baki:float,
     *     collection_rate:?float,
     *     confirmed_rate:?float,
     *     shop_count:int,
     *     receipt_count:int
     * }
     */
    public function periodStats(Collection $people, float $bakiTotal): array
    {
        $received = round((float) $people->sum('received'), 2);
        $pending = round((float) $people->sum('pending'), 2);
        $youCollected = round((float) $people->sum('you_collected'), 2);
        $collected = round($received + $pending, 2);
        $pool = round($collected + $bakiTotal, 2);
        $collectionRate = $pool > 0.009 ? round(($collected / $pool) * 100, 1) : null;
        $confirmedRate = $collected > 0.009 ? round(($received / $collected) * 100, 1) : null;

        return [
            'received' => $received,
            'pending' => $pending,
            'you_collected' => $youCollected,
            'collected' => $collected,
            'baki' => $bakiTotal,
            'collection_rate' => $collectionRate,
            'confirmed_rate' => $confirmedRate,
            'shop_count' => $people->count(),
            'receipt_count' => (int) $people->sum('receipts'),
        ];
    }

    /**
     * @param  Collection<int, int|string>|array<int, int|string>  $shopIds
     * @return array<int, float>
     */
    private function pendingCollectedByShop($shopIds): array
    {
        $ids = collect($shopIds)->filter()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        return Payment::query()
            ->whereIn('shop_id', $ids)
            ->where('status', Payment::STATUS_PENDING)
            ->selectRaw('shop_id, SUM(amount) as total')
            ->groupBy('shop_id')
            ->pluck('total', 'shop_id')
            ->map(fn ($n) => round((float) $n, 2))
            ->all();
    }

    private function pendingCollectedForShop(int $shopId): float
    {
        return round((float) Payment::query()
            ->where('shop_id', $shopId)
            ->where('status', Payment::STATUS_PENDING)
            ->sum('amount'), 2);
    }

    /**
     * @return Collection<int, Payment>
     */
    public function receipts(User $viewer, int $limit = 80, ?Carbon $from = null, ?Carbon $to = null): Collection
    {
        $shopIds = $this->shopsFor($viewer)->pluck('id');
        if ($shopIds->isEmpty()) {
            return collect();
        }

        $query = Payment::query()
            ->with(['shop', 'creator', 'invoice'])
            ->whereIn('shop_id', $shopIds)
            ->whereIn('status', [Payment::STATUS_VERIFIED, Payment::STATUS_PENDING]);

        $this->applyPaymentPeriod($query, $from, $to);

        return $query
            ->orderByRaw('COALESCE(paid_at, created_at) DESC')
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Filter payments by paid_at when set, otherwise created_at.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\Payment>  $query
     */
    private function applyPaymentPeriod($query, ?Carbon $from, ?Carbon $to): void
    {
        if ($from === null && $to === null) {
            return;
        }

        $query->where(function ($q) use ($from, $to) {
            $q->where(function ($inner) use ($from, $to) {
                $inner->whereNotNull('paid_at')
                    ->when($from, fn ($w) => $w->where('paid_at', '>=', $from))
                    ->when($to, fn ($w) => $w->where('paid_at', '<=', $to));
            })->orWhere(function ($inner) use ($from, $to) {
                $inner->whereNull('paid_at')
                    ->when($from, fn ($w) => $w->where('created_at', '>=', $from))
                    ->when($to, fn ($w) => $w->where('created_at', '<=', $to));
            });
        });
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
