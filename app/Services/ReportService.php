<?php

namespace App\Services;

use App\Models\Commission;
use App\Models\InventoryLedger;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductReturn;
use App\Models\Purchase;
use App\Models\SalesTarget;
use App\Models\Shipment;
use App\Models\Shop;
use App\Models\WarehouseStock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportService
{
    /**
     * @return array<string, array{title:string,description:string,group:string}>
     */
    public function catalog(): array
    {
        return [
            'overview' => [
                'title' => 'Admin sales overview',
                'description' => 'All salesmen, total revenue, profit and product-wise sales for the selected dates.',
                'group' => 'sales',
            ],
            'total-sales' => [
                'title' => 'Total sales',
                'description' => 'Total value of products sold in the selected period, with cost and profit.',
                'group' => 'sales',
            ],
            'salesmen' => [
                'title' => 'Salesman-wise',
                'description' => 'How much each salesman sold and the profit those sales generated.',
                'group' => 'sales',
            ],
            'salesman-stock' => [
                'title' => 'Salesman stock & delivery',
                'description' => 'Per salesman: products received for delivery, delivered to shops, returned, and remaining on hand.',
                'group' => 'operations',
            ],
            'best-sellers' => [
                'title' => 'Best-selling products',
                'description' => 'Products with the highest sales volume in the selected period.',
                'group' => 'sales',
            ],
            'most-profitable' => [
                'title' => 'Most profitable products',
                'description' => 'Products that generated the highest profit after landed cost.',
                'group' => 'sales',
            ],
            'product-sales' => [
                'title' => 'Product-wise sales',
                'description' => 'Quantity sold, revenue and profit for every product in the period.',
                'group' => 'sales',
            ],
            'sales' => [
                'title' => 'Sales register',
                'description' => 'Order-by-order billed sales, shops and salesmen for the selected period.',
                'group' => 'operations',
            ],
            'credit' => [
                'title' => 'Outstanding credit',
                'description' => 'Shop-wise receivables, ageing and credit-limit utilisation.',
                'group' => 'operations',
            ],
            'inventory' => [
                'title' => 'Inventory movement',
                'description' => 'Reservations, picks, receipts and warehouse balances.',
                'group' => 'operations',
            ],
            'shipments' => [
                'title' => 'Shipment costing',
                'description' => 'Freight, customs and landed cost by inbound container.',
                'group' => 'operations',
            ],
            'commissions' => [
                'title' => 'Salesman commission',
                'description' => 'Approved collections against configurable commission rules.',
                'group' => 'operations',
            ],
            'returns' => [
                'title' => 'Returns & warranty',
                'description' => 'Defects, replacements and credit notes awaiting audit.',
                'group' => 'operations',
            ],
            'procurement' => [
                'title' => 'Procurement',
                'description' => 'Open POs, supplier exposure and shipment linkage.',
                'group' => 'operations',
            ],
            'targets' => [
                'title' => 'Target vs achievement',
                'description' => 'Territory targets, visits and conversion for the field force.',
                'group' => 'operations',
            ],
        ];
    }

    /**
     * @return array{title:string,description:string,columns:list<string>,rows:list<list<string|int|float>>,meta:array<string,mixed>,kpis?:list<array>,sections?:list<array>}
     */
    public function generate(string $key, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $from ??= now()->startOfMonth();
        $to ??= now()->endOfDay();
        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }
        $catalog = $this->catalog();
        abort_unless(isset($catalog[$key]), 404);

        return match ($key) {
            'overview' => $this->overviewReport($from, $to, $catalog[$key]),
            'total-sales' => $this->totalSalesReport($from, $to, $catalog[$key]),
            'salesmen' => $this->salesmanReport($from, $to, $catalog[$key]),
            'salesman-stock' => $this->salesmanStockReport($catalog[$key]),
            'best-sellers' => $this->bestSellersReport($from, $to, $catalog[$key]),
            'most-profitable' => $this->mostProfitableReport($from, $to, $catalog[$key]),
            'product-sales' => $this->productSalesReport($from, $to, $catalog[$key]),
            'sales' => $this->salesReport($from, $to, $catalog[$key]),
            'credit' => $this->creditReport($catalog[$key]),
            'inventory' => $this->inventoryReport($from, $to, $catalog[$key]),
            'shipments' => $this->shipmentsReport($catalog[$key]),
            'commissions' => $this->commissionsReport($from, $to, $catalog[$key]),
            'returns' => $this->returnsReport($from, $to, $catalog[$key]),
            'procurement' => $this->procurementReport($catalog[$key]),
            'targets' => $this->targetsReport($catalog[$key]),
            default => abort(404),
        };
    }

    public function csv(string $key, ?Carbon $from = null, ?Carbon $to = null): StreamedResponse
    {
        $report = $this->generate($key, $from, $to);

        return response()->streamDownload(function () use ($report) {
            $out = fopen('php://output', 'w');
            $tables = [];
            if ($report['columns'] !== [] || $report['rows'] !== []) {
                $tables[] = ['title' => $report['title'], 'columns' => $report['columns'], 'rows' => $report['rows']];
            }
            foreach ($report['sections'] ?? [] as $section) {
                $tables[] = $section;
            }
            foreach ($tables as $table) {
                if (count($tables) > 1 && ! empty($table['title'])) {
                    fputcsv($out, [$table['title']]);
                }
                fputcsv($out, $table['columns']);
                foreach ($table['rows'] as $row) {
                    fputcsv($out, $row);
                }
                fputcsv($out, []);
            }
            fclose($out);
        }, 'bynnas-'.$key.'-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * @return list<string>
     */
    public function soldStatuses(): array
    {
        return [
            Order::STATUS_APPROVED,
            Order::STATUS_PICKING,
            Order::STATUS_PICKED,
            Order::STATUS_PACKED,
            Order::STATUS_DISPATCHED,
            Order::STATUS_DELIVERED,
        ];
    }

    private function periodMeta(Carbon $from, Carbon $to, Collection $lines): array
    {
        $eco = $this->economics($lines);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'orders' => $lines->pluck('order_id')->unique()->count(),
            'qty' => $eco['qty'],
            'sales' => $eco['revenue'],
            'profit' => $eco['profit'],
        ];
    }

    /**
     * @return Collection<int, OrderItem>
     */
    private function soldLines(Carbon $from, Carbon $to): Collection
    {
        return OrderItem::query()
            ->with(['product.category', 'order.salesman', 'order.shop'])
            ->whereHas('order', function ($query) use ($from, $to) {
                $query->whereBetween('submitted_at', [$from, $to])
                    ->whereIn('status', $this->soldStatuses());
            })
            ->get();
    }

    /**
     * @return array{qty:int,revenue:float,cost:float,profit:float}
     */
    private function lineEconomics(OrderItem $item): array
    {
        $qty = (int) $item->quantity;
        $revenue = round((float) $item->line_total, 2);
        $unitCost = (float) ($item->product?->inventoryUnitCost() ?? 0);
        $cost = round($unitCost * $qty, 2);

        return [
            'qty' => $qty,
            'revenue' => $revenue,
            'cost' => $cost,
            'profit' => round($revenue - $cost, 2),
        ];
    }

    /**
     * @param  Collection<int, OrderItem>  $lines
     * @return array{qty:int,revenue:float,cost:float,profit:float}
     */
    private function economics(Collection $lines): array
    {
        $qty = 0;
        $revenue = 0.0;
        $cost = 0.0;
        foreach ($lines as $item) {
            $eco = $this->lineEconomics($item);
            $qty += $eco['qty'];
            $revenue += $eco['revenue'];
            $cost += $eco['cost'];
        }

        return [
            'qty' => $qty,
            'revenue' => round($revenue, 2),
            'cost' => round($cost, 2),
            'profit' => round($revenue - $cost, 2),
        ];
    }

    /**
     * @return list<array{label:string,value:float|int,type:string}>
     */
    private function salesKpis(Collection $lines): array
    {
        $eco = $this->economics($lines);

        return [
            ['label' => 'Total sales', 'value' => $eco['revenue'], 'type' => 'money'],
            ['label' => 'Total profit', 'value' => $eco['profit'], 'type' => 'money'],
            ['label' => 'Quantity sold', 'value' => $eco['qty'], 'type' => 'number'],
            ['label' => 'Orders', 'value' => $lines->pluck('order_id')->unique()->count(), 'type' => 'number'],
        ];
    }

    /**
     * @return Collection<int, array{product_id:?int,name:string,sku:string,category:string,qty:int,revenue:float,cost:float,profit:float,orders:int}>
     */
    private function productRollup(Collection $lines): Collection
    {
        return $lines
            ->groupBy(fn (OrderItem $item) => $item->product_id ?: 'sku-'.$item->product_sku)
            ->map(function (Collection $rows) {
                $first = $rows->first();
                $eco = $this->economics($rows);

                return [
                    'product_id' => $first?->product_id,
                    'name' => $first?->product?->name ?: $first?->product_name,
                    'sku' => $first?->product?->sku ?: $first?->product_sku,
                    'category' => $first?->product?->category?->name ?: 'Uncategorized',
                    'qty' => $eco['qty'],
                    'revenue' => $eco['revenue'],
                    'cost' => $eco['cost'],
                    'profit' => $eco['profit'],
                    'orders' => $rows->pluck('order_id')->unique()->count(),
                ];
            })
            ->values();
    }

    /**
     * @return Collection<int, array{salesman:string,orders:int,qty:int,revenue:float,cost:float,profit:float}>
     */
    private function salesmanRollup(Collection $lines): Collection
    {
        return $lines
            ->groupBy(fn (OrderItem $item) => $item->order?->salesman_id ?: 0)
            ->map(function (Collection $rows) {
                $eco = $this->economics($rows);

                return [
                    'salesman' => $rows->first()?->order?->salesman?->name ?: 'Unassigned',
                    'orders' => $rows->pluck('order_id')->unique()->count(),
                    'qty' => $eco['qty'],
                    'revenue' => $eco['revenue'],
                    'cost' => $eco['cost'],
                    'profit' => $eco['profit'],
                ];
            })
            ->sortByDesc('revenue')
            ->values();
    }

    /**
     * @param  list<string>  $types
     * @param  list<array{label:string,value:float|int,type:string}>  $kpis
     * @param  list<array{title:string,columns:list<string>,rows:list<list<mixed>>,column_types?:list<string>}>  $sections
     */
    private function pack(array $meta, array $columns, array $rows, array $extra = [], array $kpis = [], array $sections = [], array $types = []): array
    {
        return [
            'title' => $meta['title'],
            'description' => $meta['description'],
            'columns' => $columns,
            'column_types' => $types,
            'rows' => $rows,
            'kpis' => $kpis,
            'sections' => $sections,
            'meta' => $extra,
        ];
    }

    private function overviewReport(Carbon $from, Carbon $to, array $meta): array
    {
        $lines = $this->soldLines($from, $to);
        $salesmen = $this->salesmanRollup($lines);
        $products = $this->productRollup($lines);

        return $this->pack(
            $meta,
            [],
            [],
            $this->periodMeta($from, $to, $lines) + ['salesmen' => $salesmen->count()],
            $this->salesKpis($lines),
            [
                [
                    'title' => 'Salesman-wise sales & profit',
                    'columns' => ['Salesman', 'Orders', 'Qty', 'Sales', 'Cost', 'Profit', 'Margin %'],
                    'column_types' => ['text', 'number', 'number', 'money', 'money', 'money', 'percent'],
                    'rows' => $salesmen->map(fn (array $row) => $this->salesmanRow($row))->all(),
                ],
                [
                    'title' => 'Product-wise sales',
                    'columns' => ['Product', 'SKU', 'Category', 'Qty', 'Sales', 'Cost', 'Profit'],
                    'column_types' => ['text', 'text', 'text', 'number', 'money', 'money', 'money'],
                    'rows' => $products->sortByDesc('qty')->map(fn (array $row) => $this->productRow($row))->all(),
                ],
                [
                    'title' => 'Best-selling products',
                    'columns' => ['Product', 'SKU', 'Qty', 'Sales', 'Orders'],
                    'column_types' => ['text', 'text', 'number', 'money', 'number'],
                    'rows' => $products->sortByDesc('qty')->take(10)->map(fn (array $row) => [
                        $row['name'], $row['sku'], $row['qty'], $row['revenue'], $row['orders'],
                    ])->values()->all(),
                ],
                [
                    'title' => 'Most profitable products',
                    'columns' => ['Product', 'SKU', 'Sales', 'Cost', 'Profit', 'Margin %'],
                    'column_types' => ['text', 'text', 'money', 'money', 'money', 'percent'],
                    'rows' => $products->sortByDesc('profit')->take(10)->map(fn (array $row) => [
                        $row['name'], $row['sku'], $row['revenue'], $row['cost'], $row['profit'], $this->margin($row['profit'], $row['revenue']),
                    ])->values()->all(),
                ],
            ],
        );
    }

    private function totalSalesReport(Carbon $from, Carbon $to, array $meta): array
    {
        $lines = $this->soldLines($from, $to);
        $orders = $lines->groupBy('order_id')->map(function (Collection $rows) {
            $order = $rows->first()?->order;
            $eco = $this->economics($rows);

            return [
                $order?->number,
                $order?->shop?->name ?: '—',
                $order?->salesman?->name ?: 'Unassigned',
                $eco['qty'],
                $eco['revenue'],
                $eco['cost'],
                $eco['profit'],
                $order?->submitted_at?->format('Y-m-d H:i'),
            ];
        })->values();

        return $this->pack(
            $meta,
            ['Order', 'Shop', 'Salesman', 'Qty', 'Sales', 'Cost', 'Profit', 'Date'],
            $orders->all(),
            $this->periodMeta($from, $to, $lines),
            $this->salesKpis($lines),
            [],
            ['text', 'text', 'text', 'number', 'money', 'money', 'money', 'text'],
        );
    }

    private function salesmanReport(Carbon $from, Carbon $to, array $meta): array
    {
        $lines = $this->soldLines($from, $to);
        $rows = $this->salesmanRollup($lines);

        return $this->pack(
            $meta,
            ['Salesman', 'Orders', 'Qty', 'Sales', 'Cost', 'Profit', 'Margin %'],
            $rows->map(fn (array $row) => $this->salesmanRow($row))->all(),
            $this->periodMeta($from, $to, $lines) + ['salesmen' => $rows->count()],
            $this->salesKpis($lines),
            [],
            ['text', 'number', 'number', 'money', 'money', 'money', 'percent'],
        );
    }

    private function salesmanStockReport(array $meta): array
    {
        $stock = app(DeliveryStockService::class);
        $salesmen = \App\Models\User::query()
            ->where('portal', \App\Models\User::PORTAL_SALESMAN)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $rows = [];
        foreach ($salesmen as $salesman) {
            $balances = $stock->salesmanBalances($salesman);
            if ($balances->isEmpty()) {
                continue;
            }
            foreach ($balances as $line) {
                $rows[] = [
                    $salesman->name,
                    $line['sku'] ?: '—',
                    $line['name'],
                    $line['received'],
                    $line['delivered'],
                    $line['returned'],
                    $line['remaining'],
                ];
            }
        }

        return $this->pack(
            $meta,
            ['Salesman', 'SKU', 'Product', 'Received', 'Delivered', 'Returned', 'Remaining'],
            $rows,
            ['salesmen' => $salesmen->count(), 'lines' => count($rows)],
            [
                ['label' => 'Lines', 'value' => count($rows)],
                ['label' => 'Remaining units', 'value' => collect($rows)->sum(fn ($r) => (int) $r[6])],
            ],
            [],
            ['text', 'text', 'text', 'number', 'number', 'number', 'number'],
        );
    }

    private function bestSellersReport(Carbon $from, Carbon $to, array $meta): array
    {
        $lines = $this->soldLines($from, $to);
        $products = $this->productRollup($lines)->sortByDesc('qty')->values();

        return $this->pack(
            $meta,
            ['Rank', 'Product', 'SKU', 'Category', 'Qty', 'Sales', 'Orders'],
            $products->values()->map(fn (array $row, int $i) => [
                $i + 1, $row['name'], $row['sku'], $row['category'], $row['qty'], $row['revenue'], $row['orders'],
            ])->all(),
            $this->periodMeta($from, $to, $lines),
            $this->salesKpis($lines),
            [],
            ['number', 'text', 'text', 'text', 'number', 'money', 'number'],
        );
    }

    private function mostProfitableReport(Carbon $from, Carbon $to, array $meta): array
    {
        $lines = $this->soldLines($from, $to);
        $products = $this->productRollup($lines)->sortByDesc('profit')->values();

        return $this->pack(
            $meta,
            ['Rank', 'Product', 'SKU', 'Sales', 'Cost', 'Profit', 'Margin %'],
            $products->values()->map(fn (array $row, int $i) => [
                $i + 1, $row['name'], $row['sku'], $row['revenue'], $row['cost'], $row['profit'], $this->margin($row['profit'], $row['revenue']),
            ])->all(),
            $this->periodMeta($from, $to, $lines),
            $this->salesKpis($lines),
            [],
            ['number', 'text', 'text', 'money', 'money', 'money', 'percent'],
        );
    }

    private function productSalesReport(Carbon $from, Carbon $to, array $meta): array
    {
        $lines = $this->soldLines($from, $to);
        $products = $this->productRollup($lines)->sortBy('name')->values();

        return $this->pack(
            $meta,
            ['Product', 'SKU', 'Category', 'Qty', 'Sales', 'Cost', 'Profit', 'Avg selling price'],
            $products->map(fn (array $row) => [
                $row['name'],
                $row['sku'],
                $row['category'],
                $row['qty'],
                $row['revenue'],
                $row['cost'],
                $row['profit'],
                $row['qty'] > 0 ? round($row['revenue'] / $row['qty'], 2) : 0,
            ])->all(),
            $this->periodMeta($from, $to, $lines) + ['products' => $products->count()],
            $this->salesKpis($lines),
            [],
            ['text', 'text', 'text', 'number', 'money', 'money', 'money', 'money'],
        );
    }

    /**
     * @param  array{salesman:string,orders:int,qty:int,revenue:float,cost:float,profit:float}  $row
     * @return list<string|int|float>
     */
    private function salesmanRow(array $row): array
    {
        return [
            $row['salesman'],
            $row['orders'],
            $row['qty'],
            $row['revenue'],
            $row['cost'],
            $row['profit'],
            $this->margin($row['profit'], $row['revenue']),
        ];
    }

    /**
     * @param  array{name:string,sku:string,category:string,qty:int,revenue:float,cost:float,profit:float}  $row
     * @return list<string|int|float>
     */
    private function productRow(array $row): array
    {
        return [
            $row['name'],
            $row['sku'],
            $row['category'],
            $row['qty'],
            $row['revenue'],
            $row['cost'],
            $row['profit'],
        ];
    }

    private function margin(float $profit, float $revenue): float
    {
        if ($revenue <= 0) {
            return 0;
        }

        return round(($profit / $revenue) * 100, 1);
    }

    private function salesReport(Carbon $from, Carbon $to, array $meta): array
    {
        $orders = Order::query()
            ->with(['shop', 'salesman'])
            ->whereBetween('submitted_at', [$from, $to])
            ->whereIn('status', $this->soldStatuses())
            ->latest('submitted_at')
            ->get();

        return $this->pack(
            $meta,
            ['Order', 'Shop', 'Salesman', 'Source', 'Status', 'Total', 'Submitted'],
            $orders->map(fn (Order $order) => [
                $order->number,
                $order->shop?->name,
                $order->salesman?->name ?: '—',
                $order->source,
                $order->status,
                (float) $order->total,
                $order->submitted_at?->format('Y-m-d H:i'),
            ])->all(),
            ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'count' => $orders->count(), 'sum' => (float) $orders->sum('total')],
            [
                ['label' => 'Total sales', 'value' => (float) $orders->sum('total'), 'type' => 'money'],
                ['label' => 'Orders', 'value' => $orders->count(), 'type' => 'number'],
            ],
            [],
            ['text', 'text', 'text', 'text', 'text', 'money', 'text'],
        );
    }

    private function creditReport(array $meta): array
    {
        $shops = Shop::query()
            ->whereIn('status', [Shop::STATUS_ACTIVE, Shop::STATUS_ON_HOLD])
            ->orderByDesc('outstanding_balance')
            ->get();

        return $this->pack(
            $meta,
            ['Shop', 'Code', 'Status', 'Credit limit', 'Outstanding', 'Available', 'Utilisation %'],
            $shops->map(function (Shop $shop) {
                $limit = (float) $shop->credit_limit;
                $out = (float) $shop->outstanding_balance;
                $util = $limit > 0 ? round(($out / $limit) * 100, 1) : 0;

                return [
                    $shop->name,
                    $shop->code,
                    $shop->status,
                    $limit,
                    $out,
                    max(0, $limit - $out),
                    $util,
                ];
            })->all(),
            ['count' => $shops->count(), 'sum' => (float) $shops->sum('outstanding_balance')],
            [],
            [],
            ['text', 'text', 'text', 'money', 'money', 'money', 'percent'],
        );
    }

    private function inventoryReport(Carbon $from, Carbon $to, array $meta): array
    {
        $stocks = WarehouseStock::query()->with(['warehouse', 'product'])->orderByDesc('qty_on_hand')->limit(200)->get();
        $movements = InventoryLedger::query()
            ->with(['product', 'warehouse'])
            ->whereBetween('created_at', [$from, $to])
            ->latest()
            ->limit(100)
            ->get();

        $rows = $stocks->map(fn (WarehouseStock $stock) => [
            $stock->warehouse?->name,
            $stock->product?->sku,
            $stock->product?->name,
            (int) $stock->qty_on_hand,
            (int) $stock->qty_reserved,
            (int) $stock->qty_on_hand - (int) $stock->qty_reserved,
            'balance',
        ])->all();

        foreach ($movements as $movement) {
            $rows[] = [
                $movement->warehouse?->name,
                $movement->product?->sku,
                $movement->product?->name,
                (int) $movement->qty_delta,
                '—',
                '—',
                $movement->type,
            ];
        }

        return $this->pack(
            $meta,
            ['Warehouse', 'SKU', 'Product', 'On hand / qty', 'Reserved', 'Available', 'Type'],
            $rows,
            ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        );
    }

    private function shipmentsReport(array $meta): array
    {
        $shipments = Shipment::query()->with('supplier')->latest()->limit(100)->get();

        return $this->pack(
            $meta,
            ['Shipment', 'Supplier', 'Origin', 'Status', 'Freight', 'Customs', 'Insurance', 'Other', 'Landed total'],
            $shipments->map(fn (Shipment $shipment) => [
                $shipment->number,
                $shipment->supplier?->name,
                $shipment->origin,
                $shipment->status,
                (float) ($shipment->freight_cost ?? 0),
                (float) ($shipment->customs_duty ?? 0),
                (float) ($shipment->insurance_cost ?? 0),
                (float) ($shipment->other_cost ?? 0),
                (float) ($shipment->total_landed_cost ?? 0),
            ])->all(),
            ['count' => $shipments->count(), 'sum' => (float) $shipments->sum('total_landed_cost')],
            [],
            [],
            ['text', 'text', 'text', 'text', 'money', 'money', 'money', 'money', 'money'],
        );
    }

    private function commissionsReport(Carbon $from, Carbon $to, array $meta): array
    {
        $rows = Commission::query()
            ->with('salesman')
            ->whereBetween('created_at', [$from, $to])
            ->latest()
            ->get();

        return $this->pack(
            $meta,
            ['Commission', 'Salesman', 'Type', 'Period', 'Base', 'Rate %', 'Amount', 'Status'],
            $rows->map(fn (Commission $commission) => [
                $commission->number,
                $commission->salesman?->name,
                $commission->type,
                $commission->periodLabel(),
                (float) $commission->base_amount,
                (float) $commission->rate_percent,
                (float) $commission->commission_amount,
                $commission->status,
            ])->all(),
            ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'sum' => (float) $rows->sum('commission_amount')],
            [],
            [],
            ['text', 'text', 'text', 'text', 'money', 'percent', 'money', 'text'],
        );
    }

    private function returnsReport(Carbon $from, Carbon $to, array $meta): array
    {
        $rows = ProductReturn::query()
            ->with(['shop', 'order'])
            ->whereBetween('created_at', [$from, $to])
            ->latest()
            ->get();

        return $this->pack(
            $meta,
            ['Return', 'Shop', 'Order', 'Reason', 'Total', 'Restock', 'Credit', 'Status'],
            $rows->map(fn (ProductReturn $return) => [
                $return->number,
                $return->shop?->name,
                $return->order?->number ?: '—',
                $return->reason_type,
                (float) $return->total,
                $return->restock ? 'Yes' : 'No',
                $return->credit_issued ? 'Yes' : 'No',
                $return->status,
            ])->all(),
            ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'sum' => (float) $rows->sum('total')],
            [],
            [],
            ['text', 'text', 'text', 'text', 'money', 'text', 'text', 'text'],
        );
    }

    private function procurementReport(array $meta): array
    {
        $purchases = Purchase::query()->with('supplier')->latest()->limit(100)->get();

        return $this->pack(
            $meta,
            ['PO', 'Supplier', 'Status', 'Currency', 'FX', 'Total foreign', 'Total BDT', 'Ordered'],
            $purchases->map(fn (Purchase $purchase) => [
                $purchase->number,
                $purchase->supplier?->name,
                $purchase->status,
                $purchase->currency,
                (float) ($purchase->exchange_rate ?? 0),
                (float) ($purchase->subtotal_foreign ?? 0),
                (float) ($purchase->subtotal_bdt ?? 0),
                $purchase->ordered_at?->format('Y-m-d') ?: $purchase->created_at?->format('Y-m-d'),
            ])->all(),
            ['count' => $purchases->count()],
        );
    }

    private function targetsReport(array $meta): array
    {
        $rows = SalesTarget::query()
            ->with(['salesman', 'territory'])
            ->where('year', now()->year)
            ->where('month', now()->month)
            ->orderBy('salesman_id')
            ->get();

        return $this->pack(
            $meta,
            ['Period', 'Salesman', 'Territory', 'Target', 'Achieved', 'Collected', '%', 'Met'],
            $rows->map(fn (SalesTarget $target) => [
                $target->periodLabel(),
                $target->salesman?->name,
                $target->territory?->name ?: '—',
                (float) $target->target_amount,
                (float) $target->achieved_amount,
                (float) $target->collected_amount,
                $target->achievementPercent(),
                $target->target_met ? 'Yes' : 'No',
            ])->all(),
            ['period' => now()->format('Y-m'), 'hit' => $rows->where('target_met', true)->count()],
        );
    }
}
