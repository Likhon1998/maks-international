<?php

namespace App\Services;

use App\Models\Commission;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductReturn;
use App\Models\Purchase;
use App\Models\SalaryPayment;
use App\Models\SalesTarget;
use App\Models\Shipment;
use App\Models\Shop;
use App\Models\User;
use App\Models\WarehouseStock;
use Illuminate\Support\Carbon;

class AnalyticsService
{
    public function __construct(private ReportService $reports) {}

    /**
     * @return array<string, mixed>
     */
    public function dashboard(?\App\Models\User $user = null): array
    {
        $monthStart = now()->startOfMonth()->copy();
        $monthEnd = now()->endOfMonth()->copy();
        $prevStart = now()->subMonth()->startOfMonth()->copy();
        $prevEnd = now()->subMonth()->endOfMonth()->copy();

        $systemTotals = null;
        if ($user?->isSuperAdmin()) {
            $systemTotals = $this->reports->systemTotals();
        }

        $salesThis = (float) Order::query()
            ->whereIn('status', [Order::STATUS_DELIVERED, Order::STATUS_DISPATCHED, Order::STATUS_PACKED, Order::STATUS_APPROVED, Order::STATUS_PICKED, Order::STATUS_PICKING])
            ->whereBetween('submitted_at', [$monthStart, $monthEnd])
            ->sum('total');

        $salesPrev = (float) Order::query()
            ->whereIn('status', [Order::STATUS_DELIVERED, Order::STATUS_DISPATCHED, Order::STATUS_PACKED, Order::STATUS_APPROVED, Order::STATUS_PICKED, Order::STATUS_PICKING])
            ->whereBetween('submitted_at', [$prevStart, $prevEnd])
            ->sum('total');

        $ordersThis = Order::query()->whereBetween('submitted_at', [$monthStart, $monthEnd])->count();
        $ordersPrev = Order::query()->whereBetween('submitted_at', [$prevStart, $prevEnd])->count();

        $shops = Shop::query()->whereIn('status', [Shop::STATUS_ACTIVE, Shop::STATUS_ON_HOLD])->count();
        $shopsThisMonth = Shop::query()
            ->whereBetween('created_at', [$monthStart, $monthEnd])
            ->count();
        $shopsPrevMonth = Shop::query()
            ->whereBetween('created_at', [$prevStart, $prevEnd])
            ->count();
        $outstanding = (float) Shop::query()->sum('outstanding_balance');
        $products = Product::query()->where('status', Product::STATUS_ACTIVE)->count();
        $salesmenCount = User::query()->where('portal', User::PORTAL_SALESMAN)->where('is_active', true)->count();
        $salaryPaidMonth = (float) SalaryPayment::query()
            ->where('year', now()->year)
            ->where('month', now()->month)
            ->where('status', SalaryPayment::STATUS_PAID)
            ->selectRaw('COALESCE(SUM(amount + bonus), 0) as total')
            ->value('total');
        $commissionMonth = (float) Commission::query()
            ->where('year', now()->year)
            ->where('month', now()->month)
            ->where('status', '!=', Commission::STATUS_REJECTED)
            ->sum('commission_amount');
        $gradedShops = Shop::query()->whereNotNull('grade')->count();
        $gradedSalesmen = \App\Models\SalesmanProfile::query()->whereNotNull('grade')->count();

        $statusCounts = Order::query()
            ->when($user, fn ($q) => $q->visibleTo($user))
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $recentOrders = Order::query()
            ->when($user, fn ($q) => $q->visibleTo($user))
            ->with(['shop', 'salesman'])
            ->latest('submitted_at')
            ->limit(8)
            ->get();

        $topProducts = Product::query()
            ->orderByDesc('stock_on_hand')
            ->limit(5)
            ->get(['id', 'name', 'sku', 'stock_on_hand', 'wholesale_price']);

        $shipments = Shipment::query()->latest()->limit(5)->get();

        return [
            'stats' => [
                [
                    'title' => 'Total Sales (This Month)',
                    'value' => $salesThis,
                    'delta' => $this->deltaLabel($salesThis, $salesPrev),
                    'up' => $salesThis >= $salesPrev,
                    'color' => '#a855f7',
                ],
                [
                    'title' => 'Total Orders',
                    'value' => $ordersThis,
                    'delta' => $this->deltaLabel($ordersThis, $ordersPrev),
                    'up' => $ordersThis >= $ordersPrev,
                    'color' => '#3b82f6',
                    'raw' => true,
                ],
                [
                    'title' => 'New Shops (This Month)',
                    'value' => $shopsThisMonth,
                    'delta' => $this->deltaLabel($shopsThisMonth, $shopsPrevMonth).' · '.$shops.' active',
                    'up' => $shopsThisMonth >= $shopsPrevMonth,
                    'color' => '#8b5cf6',
                    'raw' => true,
                ],
                [
                    'title' => 'Outstanding Amount',
                    'value' => $outstanding,
                    'delta' => 'Open invoice balances',
                    'up' => false,
                    'color' => '#f97316',
                ],
                [
                    'title' => 'Active Products',
                    'value' => $products,
                    'delta' => 'Catalogue SKUs',
                    'up' => true,
                    'color' => '#14b8a6',
                    'raw' => true,
                ],
                [
                    'title' => 'Active Salesmen',
                    'value' => $salesmenCount,
                    'delta' => $gradedSalesmen.' graded · '.$gradedShops.' graded shops',
                    'up' => true,
                    'color' => '#0ea5e9',
                    'raw' => true,
                ],
                [
                    'title' => 'Salary paid (month)',
                    'value' => $salaryPaidMonth,
                    'delta' => 'Commissions ৳ '.number_format($commissionMonth, 0),
                    'up' => true,
                    'color' => '#22c55e',
                ],
            ],
            'salesSeries' => $this->dailySalesSeries(14),
            'orderStatus' => [
                'pending_audit' => (int) ($statusCounts[Order::STATUS_PENDING_AUDIT] ?? 0),
                'approved' => (int) (($statusCounts[Order::STATUS_APPROVED] ?? 0) + ($statusCounts[Order::STATUS_PICKING] ?? 0)),
                'processing' => (int) (($statusCounts[Order::STATUS_PICKED] ?? 0) + ($statusCounts[Order::STATUS_PACKED] ?? 0)),
                'dispatched' => (int) ($statusCounts[Order::STATUS_DISPATCHED] ?? 0),
                'delivered' => (int) ($statusCounts[Order::STATUS_DELIVERED] ?? 0),
            ],
            'recentOrders' => $recentOrders,
            'topProducts' => $topProducts,
            'shipments' => $shipments,
            'pendingAudit' => (int) ($statusCounts[Order::STATUS_PENDING_AUDIT] ?? 0),
            'pendingPayments' => Payment::query()
                ->when($user, fn ($q) => $q->whereHas('shop', fn ($s) => $s->visibleTo($user)))
                ->where('status', Payment::STATUS_PENDING)->count(),
            'openInvoices' => Invoice::query()
                ->when($user, fn ($q) => $q->whereHas('shop', fn ($s) => $s->visibleTo($user)))
                ->whereIn('status', [Invoice::STATUS_ISSUED, Invoice::STATUS_PARTIAL])->count(),
            'systemTotals' => $systemTotals,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function analyticsPage(): array
    {
        $dash = $this->dashboard();

        $billedVsCollected = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = now()->subDays($i)->startOfDay();
            $end = (clone $day)->endOfDay();
            $billedVsCollected[] = [
                'label' => $day->format('d M'),
                'billed' => (float) Invoice::query()->whereBetween('issued_at', [$day, $end])->sum('total'),
                'collected' => (float) Payment::query()->where('status', Payment::STATUS_VERIFIED)->whereBetween('verified_at', [$day, $end])->sum('amount'),
            ];
        }

        $targets = SalesTarget::query()
            ->with(['salesman', 'territory'])
            ->where('year', now()->year)
            ->where('month', now()->month)
            ->orderByDesc('achieved_amount')
            ->get();

        $fillRate = $this->fillRate();

        return [
            'kpis' => [
                ['Gross billed (month)', \App\Support\DemoData::taka($dash['stats'][0]['value']), 'Approved+ wholesale'],
                ['Fill rate', $fillRate.'%', 'Delivered vs approved lines'],
                ['Credit at risk', \App\Support\DemoData::taka($dash['stats'][3]['value']), 'Shop outstanding'],
                ['Target hit', $targets->where('target_met', true)->count().' / '.$targets->count(), 'Active salesmen'],
            ],
            'billedVsCollected' => $billedVsCollected,
            'targets' => $targets,
            'commissionMonth' => (float) Commission::query()
                ->where('year', now()->year)
                ->where('month', now()->month)
                ->whereNotIn('status', [Commission::STATUS_REJECTED])
                ->sum('commission_amount'),
            'returnsMonth' => (float) ProductReturn::query()
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->sum('total'),
        ];
    }

    /**
     * @return list<array{label:string,value:float}>
     */
    public function dailySalesSeries(int $days = 14): array
    {
        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = now()->subDays($i)->startOfDay();
            $end = (clone $day)->endOfDay();
            $series[] = [
                'label' => $day->format('d M'),
                'value' => (float) Order::query()
                    ->whereNotIn('status', [Order::STATUS_REJECTED, Order::STATUS_CANCELLED, Order::STATUS_DRAFT])
                    ->whereBetween('submitted_at', [$day, $end])
                    ->sum('total'),
            ];
        }

        return $series;
    }

    private function fillRate(): int
    {
        $approved = Order::query()->whereIn('status', [
            Order::STATUS_APPROVED, Order::STATUS_PICKING, Order::STATUS_PICKED,
            Order::STATUS_PACKED, Order::STATUS_DISPATCHED, Order::STATUS_DELIVERED,
        ])->count();

        if ($approved === 0) {
            return 100;
        }

        $delivered = Order::query()->where('status', Order::STATUS_DELIVERED)->count();

        return (int) round(($delivered / $approved) * 100);
    }

    private function deltaLabel(float|int $current, float|int $previous): string
    {
        if ($previous <= 0) {
            return $current > 0 ? 'New activity this month' : 'No prior month data';
        }

        $pct = round((($current - $previous) / $previous) * 100, 1);

        return abs($pct).'% vs last month';
    }
}
