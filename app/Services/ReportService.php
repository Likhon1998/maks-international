<?php

namespace App\Services;

use App\Models\Commission;
use App\Models\CommissionRule;
use App\Models\InventoryLedger;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductReturn;
use App\Models\Purchase;
use App\Models\SalesTarget;
use App\Models\Shipment;
use App\Models\Shop;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Support\Warranty;
use Barryvdh\DomPDF\Facade\Pdf;
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
                'description' => 'Overall sales, orders, average order value, profit and discounts for the selected period.',
                'group' => 'sales',
            ],
            'salesmen' => [
                'title' => 'Salesman-wise',
                'description' => 'Each salesman separately: total sales, orders, average order value, profit and discounts.',
                'group' => 'sales',
            ],
            'salesman-stock' => [
                'title' => 'Salesman stock & delivery',
                'description' => 'Per salesman: products received for delivery, delivered to shops, returned, and remaining on hand.',
                'group' => 'operations',
            ],
            'best-sellers' => [
                'title' => 'Best-selling products',
                'description' => 'Product quantity, revenue, profit and area demand for a day, month or custom date range.',
                'group' => 'sales',
            ],
            'most-profitable' => [
                'title' => 'Most profitable products',
                'description' => 'Each product separately: quantity, sales, purchase cost, profit and margin, with the top earner first.',
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
                'description' => 'Opening stock, stock in/out, returns, damage, adjustments, closing stock and movement history.',
                'group' => 'operations',
            ],
            'shipments' => [
                'title' => 'Shipment costing',
                'description' => 'Freight, customs and landed cost by inbound container.',
                'group' => 'operations',
            ],
            'commissions' => [
                'title' => 'Salesman commission',
                'description' => 'Each salesman: sales, profit, commission rate, earned, paid, due and payment history.',
                'group' => 'operations',
            ],
            'returns' => [
                'title' => 'Returns & warranty',
                'description' => 'Customer/shop returns, warranty claims, refunds, replacements, repairs and restock condition.',
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
    /**
     * @param  array{q?:string,category_id?:int|string,brand_id?:int|string,flow?:string}  $filters
     */
    public function generate(string $key, ?Carbon $from = null, ?Carbon $to = null, array $filters = []): array
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
            'inventory' => $this->inventoryReport($from, $to, $catalog[$key], $filters),
            'shipments' => $this->shipmentsReport($catalog[$key]),
            'commissions' => $this->commissionsReport($from, $to, $catalog[$key]),
            'returns' => $this->returnsReport($from, $to, $catalog[$key], $filters),
            'procurement' => $this->procurementReport($catalog[$key]),
            'targets' => $this->targetsReport($catalog[$key]),
            default => abort(404),
        };
    }

    /**
     * @param  array{q?:string,category_id?:int|string,brand_id?:int|string,flow?:string}  $filters
     */
    public function csv(string $key, ?Carbon $from = null, ?Carbon $to = null, array $filters = []): StreamedResponse
    {
        $report = $this->generate($key, $from, $to, $filters);

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
        }, 'maks-'.$key.'-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @param  array{q?:string,category_id?:int|string,brand_id?:int|string,flow?:string}  $filters
     */
    public function download(string $key, ?Carbon $from, ?Carbon $to, array $filters = [], string $format = 'csv')
    {
        return match ($format) {
            'pdf' => $this->pdf($key, $from, $to, $filters),
            'xlsx', 'xls', 'excel' => $this->excel($key, $from, $to, $filters),
            default => $this->csv($key, $from, $to, $filters),
        };
    }

    /**
     * @param  array{q?:string,category_id?:int|string,brand_id?:int|string,flow?:string}  $filters
     */
    public function excel(string $key, ?Carbon $from = null, ?Carbon $to = null, array $filters = []): StreamedResponse
    {
        $report = $this->generate($key, $from, $to, $filters);
        $tables = $this->reportTables($report);
        $filename = 'maks-'.$key.'-'.now()->format('Ymd-His').'.xls';

        return response()->streamDownload(function () use ($report, $tables) {
            echo '<?xml version="1.0" encoding="UTF-8"?>'."\n";
            echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">';
            echo '<Worksheet ss:Name="'.htmlspecialchars(mb_substr($report['title'], 0, 31)).'"><Table>';
            foreach ($tables as $table) {
                if (! empty($table['title'])) {
                    echo '<Row><Cell ss:MergeAcross="'.max(0, count($table['columns']) - 1).'"><Data ss:Type="String">'.htmlspecialchars((string) $table['title']).'</Data></Cell></Row>';
                }
                echo '<Row>';
                foreach ($table['columns'] as $col) {
                    echo '<Cell><Data ss:Type="String">'.htmlspecialchars((string) $col).'</Data></Cell>';
                }
                echo '</Row>';
                foreach ($table['rows'] as $row) {
                    echo '<Row>';
                    foreach ($row as $cell) {
                        $numeric = is_numeric($cell) && $cell !== '';
                        echo '<Cell><Data ss:Type="'.($numeric ? 'Number' : 'String').'">'.htmlspecialchars((string) $cell).'</Data></Cell>';
                    }
                    echo '</Row>';
                }
                echo '<Row></Row>';
            }
            echo '</Table></Worksheet></Workbook>';
        }, $filename, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
        ]);
    }

    /**
     * @param  array{q?:string,category_id?:int|string,brand_id?:int|string,flow?:string}  $filters
     */
    public function pdf(string $key, ?Carbon $from = null, ?Carbon $to = null, array $filters = [])
    {
        $report = $this->generate($key, $from, $to, $filters);

        return Pdf::loadView('admin.reports.document', [
            'report' => $report,
            'tables' => $this->reportTables($report),
            'from' => $from?->toDateString(),
            'to' => $to?->toDateString(),
            'generatedAt' => now(),
        ])->setPaper('a4', 'landscape')->download('maks-'.$key.'-'.now()->format('Ymd-His').'.pdf');
    }

    /**
     * @return list<array{title?:string,columns:list<string>,rows:list<list<mixed>>}>
     */
    private function reportTables(array $report): array
    {
        $tables = [];
        if (($report['columns'] ?? []) !== [] || ($report['rows'] ?? []) !== []) {
            $tables[] = ['title' => $report['title'], 'columns' => $report['columns'], 'rows' => $report['rows']];
        }
        foreach ($report['sections'] ?? [] as $section) {
            $tables[] = $section;
        }

        return $tables;
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

    /**
     * System-wide sales / COGS expenses / profit (optionally date-bounded).
     *
     * @return array{sales:float,expenses:float,profit:float,qty:int,orders:int}
     */
    public function systemTotals(?Carbon $from = null, ?Carbon $to = null): array
    {
        $lines = $this->soldLines($from, $to);
        $eco = $this->economics($lines);

        return [
            'sales' => $eco['revenue'],
            'expenses' => $eco['cost'],
            'profit' => $eco['profit'],
            'qty' => $eco['qty'],
            'orders' => $lines->pluck('order_id')->unique()->count(),
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
    private function soldLines(?Carbon $from = null, ?Carbon $to = null): Collection
    {
        return OrderItem::query()
            ->with(['product.category', 'order.salesman', 'order.shop.territory'])
            ->whereHas('order', function ($query) use ($from, $to) {
                $query->whereIn('status', $this->soldStatuses());
                if ($from && $to) {
                    $query->whereBetween('submitted_at', [$from, $to]);
                } elseif ($from) {
                    $query->where('submitted_at', '>=', $from);
                } elseif ($to) {
                    $query->where('submitted_at', '<=', $to);
                }
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
     * @return array{qty:int,revenue:float,cost:float,sales:float,orders:int,discounts:float,profit:float,aov:float}
     */
    private function headlineTotals(Collection $lines): array
    {
        $eco = $this->economics($lines);
        $orders = $lines->map(fn (OrderItem $item) => $item->order)->filter()->unique('id');
        $orderCount = max($orders->count(), $lines->pluck('order_id')->unique()->count());
        $discounts = round((float) $orders->sum(fn ($order) => (float) ($order->discount_total ?? 0)), 2);
        $sales = round((float) $orders->sum(fn ($order) => (float) ($order->total ?? 0)), 2);
        if ($sales <= 0 && $eco['revenue'] > 0) {
            $sales = round(max(0, $eco['revenue'] - $discounts), 2);
        }

        return [
            'qty' => $eco['qty'],
            'revenue' => $eco['revenue'],
            'cost' => $eco['cost'],
            'sales' => $sales,
            'orders' => $orderCount,
            'discounts' => $discounts,
            'profit' => round($sales - $eco['cost'], 2),
            'aov' => $orderCount > 0 ? round($sales / $orderCount, 2) : 0.0,
        ];
    }

    /**
     * @return list<array{label:string,value:float|int,type:string}>
     */
    private function headlineKpis(Collection $lines): array
    {
        $totals = $this->headlineTotals($lines);

        return [
            ['label' => 'Total sales', 'value' => $totals['sales'], 'type' => 'money'],
            ['label' => 'Total orders', 'value' => $totals['orders'], 'type' => 'number'],
            ['label' => 'Average order value', 'value' => $totals['aov'], 'type' => 'money'],
            ['label' => 'Total profit', 'value' => $totals['profit'], 'type' => 'money'],
            ['label' => 'Total discounts', 'value' => $totals['discounts'], 'type' => 'money'],
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
     * @return Collection<int, array{salesman_id:int,salesman:string,orders:int,qty:int,revenue:float,sales:float,cost:float,profit:float,discounts:float,aov:float}>
     */
    private function salesmanRollup(Collection $lines): Collection
    {
        return $lines
            ->groupBy(fn (OrderItem $item) => (int) ($item->order?->salesman_id ?: 0))
            ->map(function (Collection $rows, $salesmanId) {
                $eco = $this->economics($rows);
                $orders = $rows->map(fn (OrderItem $item) => $item->order)
                    ->filter()
                    ->unique('id');
                $orderCount = max($orders->count(), $rows->pluck('order_id')->unique()->count());
                $discounts = round((float) $orders->sum(fn ($order) => (float) ($order->discount_total ?? 0)), 2);
                $sales = round((float) $orders->sum(fn ($order) => (float) ($order->total ?? 0)), 2);
                if ($sales <= 0 && $eco['revenue'] > 0) {
                    $sales = round(max(0, $eco['revenue'] - $discounts), 2);
                }
                $profit = round($sales - $eco['cost'], 2);

                return [
                    'salesman_id' => (int) $salesmanId,
                    'salesman' => $rows->first()?->order?->salesman?->name ?: 'Unassigned',
                    'orders' => $orderCount,
                    'qty' => $eco['qty'],
                    'revenue' => $eco['revenue'],
                    'sales' => $sales,
                    'cost' => $eco['cost'],
                    'profit' => $profit,
                    'discounts' => $discounts,
                    'aov' => $orderCount > 0 ? round($sales / $orderCount, 2) : 0.0,
                ];
            })
            ->sortByDesc('sales')
            ->values();
    }

    /**
     * Every active salesman, plus anyone with sales in the period (including Unassigned).
     *
     * @return Collection<int, array{salesman_id:int,salesman:string,orders:int,qty:int,revenue:float,sales:float,cost:float,profit:float,discounts:float,aov:float}>
     */
    private function salesmanPerformance(Collection $lines): Collection
    {
        $sold = $this->salesmanRollup($lines)->keyBy('salesman_id');

        $rows = User::query()
            ->where('portal', User::PORTAL_SALESMAN)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(function (User $user) use ($sold) {
                return $sold->get($user->id) ?? [
                    'salesman_id' => $user->id,
                    'salesman' => $user->name,
                    'orders' => 0,
                    'qty' => 0,
                    'revenue' => 0.0,
                    'sales' => 0.0,
                    'cost' => 0.0,
                    'profit' => 0.0,
                    'discounts' => 0.0,
                    'aov' => 0.0,
                ];
            });

        if ($sold->has(0)) {
            $rows = $rows->push($sold->get(0));
        }

        return $rows->sortByDesc('sales')->values();
    }

    /**
     * @param  array{salesman:string,orders:int,sales:float,aov:float,profit:float,discounts:float}  $row
     * @return list<array{label:string,value:float|int,type:string}>
     */
    private function salesmanStatCards(array $row): array
    {
        return [
            ['label' => 'Total sales', 'value' => $row['sales'], 'type' => 'money'],
            ['label' => 'Total orders', 'value' => $row['orders'], 'type' => 'number'],
            ['label' => 'Average order value', 'value' => $row['aov'], 'type' => 'money'],
            ['label' => 'Total profit', 'value' => $row['profit'], 'type' => 'money'],
            ['label' => 'Total discounts', 'value' => $row['discounts'], 'type' => 'money'],
        ];
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

        $headline = $this->headlineTotals($lines);

        return $this->pack(
            $meta,
            ['Total sales', 'Total orders', 'Average order value', 'Total profit', 'Total discounts'],
            [[
                $headline['sales'],
                $headline['orders'],
                $headline['aov'],
                $headline['profit'],
                $headline['discounts'],
            ]],
            $this->periodMeta($from, $to, $lines),
            $this->headlineKpis($lines),
            [
                [
                    'title' => 'Sold orders',
                    'columns' => ['Order', 'Shop', 'Salesman', 'Qty', 'Sales', 'Cost', 'Profit', 'Date'],
                    'column_types' => ['text', 'text', 'text', 'number', 'money', 'money', 'money', 'text'],
                    'rows' => $orders->all(),
                ],
            ],
            ['money', 'number', 'money', 'money', 'money'],
        );
    }

    private function salesmanReport(Carbon $from, Carbon $to, array $meta): array
    {
        $lines = $this->soldLines($from, $to);
        $rows = $this->salesmanPerformance($lines);

        $report = $this->pack(
            $meta,
            ['Salesman', 'Total sales', 'Total orders', 'Average order value', 'Total profit', 'Total discounts'],
            $rows->map(fn (array $row) => [
                $row['salesman'],
                $row['sales'],
                $row['orders'],
                $row['aov'],
                $row['profit'],
                $row['discounts'],
            ])->all(),
            $this->periodMeta($from, $to, $lines) + ['salesmen' => $rows->count()],
            [
                ['label' => 'Salesmen', 'value' => $rows->count(), 'type' => 'number'],
                ['label' => 'Total sales', 'value' => (float) $rows->sum('sales'), 'type' => 'money'],
                ['label' => 'Total orders', 'value' => (int) $rows->sum('orders'), 'type' => 'number'],
                ['label' => 'Total profit', 'value' => (float) $rows->sum('profit'), 'type' => 'money'],
                ['label' => 'Total discounts', 'value' => (float) $rows->sum('discounts'), 'type' => 'money'],
            ],
            [],
            ['text', 'money', 'number', 'money', 'money', 'money'],
        );

        $report['cards'] = $rows->map(fn (array $row) => [
            'title' => $row['salesman'],
            'stats' => $this->salesmanStatCards($row),
        ])->all();

        return $report;
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

    private function shopArea(?Shop $shop): string
    {
        if (! $shop) {
            return 'Unassigned';
        }

        foreach (['thana', 'city', 'district', 'location'] as $field) {
            $value = trim((string) ($shop->{$field} ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        $territory = trim((string) ($shop->territory?->name ?? ''));

        return $territory !== '' ? $territory : 'Unassigned';
    }

    /**
     * @return Collection<int, array{area:string,qty:int,revenue:float,profit:float,orders:int}>
     */
    private function areaRollup(Collection $lines): Collection
    {
        return $lines
            ->groupBy(fn (OrderItem $item) => $this->shopArea($item->order?->shop))
            ->map(function (Collection $rows, string $area) {
                $eco = $this->economics($rows);

                return [
                    'area' => $area,
                    'qty' => $eco['qty'],
                    'revenue' => $eco['revenue'],
                    'profit' => $eco['profit'],
                    'orders' => $rows->pluck('order_id')->unique()->count(),
                ];
            })
            ->sortByDesc('revenue')
            ->values();
    }

    private function bestSellersReport(Carbon $from, Carbon $to, array $meta): array
    {
        $lines = $this->soldLines($from, $to);
        $products = $this->productRollup($lines);
        $byQty = $products->sortByDesc('qty')->values();
        $byProfit = $products->sortByDesc('profit')->values();
        $areas = $this->areaRollup($lines);
        $best = $byQty->first();
        $mostProfitable = $byProfit->first();
        $topArea = $areas->first();

        $areaProducts = $lines
            ->groupBy(fn (OrderItem $item) => $this->shopArea($item->order?->shop))
            ->flatMap(function (Collection $rows, string $area) {
                return $this->productRollup($rows)
                    ->sortByDesc('qty')
                    ->map(fn (array $row) => [
                        $area,
                        $row['name'],
                        $row['sku'],
                        $row['qty'],
                        $row['revenue'],
                        $row['profit'],
                    ]);
            })
            ->values();

        return $this->pack(
            $meta,
            [],
            [],
            $this->periodMeta($from, $to, $lines),
            [
                ['label' => 'Best-selling product', 'value' => data_get($best, 'name', '—'), 'type' => 'text'],
                ['label' => 'Best-seller quantity', 'value' => (int) data_get($best, 'qty', 0), 'type' => 'number'],
                ['label' => 'Most profitable product', 'value' => data_get($mostProfitable, 'name', '—'), 'type' => 'text'],
                ['label' => 'Top product profit', 'value' => (float) data_get($mostProfitable, 'profit', 0), 'type' => 'money'],
                ['label' => 'Top area', 'value' => data_get($topArea, 'area', '—'), 'type' => 'text'],
                ['label' => 'Top area sales', 'value' => (float) data_get($topArea, 'revenue', 0), 'type' => 'money'],
            ],
            [
                [
                    'title' => 'Product-wise quantity, sales and profit',
                    'columns' => ['Rank', 'Product', 'SKU', 'Qty sold', 'Total sales', 'Profit', 'Orders'],
                    'column_types' => ['number', 'text', 'text', 'number', 'money', 'money', 'number'],
                    'rows' => $byQty->values()->map(fn (array $row, int $i) => [
                        $i + 1, $row['name'], $row['sku'], $row['qty'], $row['revenue'], $row['profit'], $row['orders'],
                    ])->all(),
                ],
                [
                    'title' => 'Most profitable products',
                    'columns' => ['Rank', 'Product', 'SKU', 'Total sales', 'Profit', 'Margin %'],
                    'column_types' => ['number', 'text', 'text', 'money', 'money', 'percent'],
                    'rows' => $byProfit->values()->map(fn (array $row, int $i) => [
                        $i + 1, $row['name'], $row['sku'], $row['revenue'], $row['profit'], $this->margin($row['profit'], $row['revenue']),
                    ])->all(),
                ],
                [
                    'title' => 'Area-wise sales',
                    'columns' => ['Rank', 'Area', 'Qty sold', 'Total sales', 'Profit', 'Orders'],
                    'column_types' => ['number', 'text', 'number', 'money', 'money', 'number'],
                    'rows' => $areas->values()->map(fn (array $row, int $i) => [
                        $i + 1, $row['area'], $row['qty'], $row['revenue'], $row['profit'], $row['orders'],
                    ])->all(),
                ],
                [
                    'title' => 'Area-wise product sales',
                    'columns' => ['Area', 'Product', 'SKU', 'Qty sold', 'Total sales', 'Profit'],
                    'column_types' => ['text', 'text', 'text', 'number', 'money', 'money'],
                    'rows' => $areaProducts->all(),
                ],
            ],
        );
    }

    private function mostProfitableReport(Carbon $from, Carbon $to, array $meta): array
    {
        $lines = $this->soldLines($from, $to);
        $products = $this->productRollup($lines)->sortByDesc('profit')->values();
        $top = $products->first();

        $report = $this->pack(
            $meta,
            ['Rank', 'Product name', 'Total quantity sold', 'Total sales amount', 'Total purchase cost', 'Total profit', 'Profit margin %'],
            $products->values()->map(fn (array $row, int $i) => [
                $i + 1,
                $row['name'],
                $row['qty'],
                $row['revenue'],
                $row['cost'],
                $row['profit'],
                $this->margin($row['profit'], $row['revenue']),
            ])->all(),
            $this->periodMeta($from, $to, $lines),
            [
                ['label' => 'Most profitable product', 'value' => data_get($top, 'name', '—'), 'type' => 'text'],
                ['label' => 'Total quantity sold', 'value' => (int) data_get($top, 'qty', 0), 'type' => 'number'],
                ['label' => 'Total sales amount', 'value' => (float) data_get($top, 'revenue', 0), 'type' => 'money'],
                ['label' => 'Total purchase cost', 'value' => (float) data_get($top, 'cost', 0), 'type' => 'money'],
                ['label' => 'Total profit', 'value' => (float) data_get($top, 'profit', 0), 'type' => 'money'],
                ['label' => 'Profit margin %', 'value' => $this->margin((float) data_get($top, 'profit', 0), (float) data_get($top, 'revenue', 0)), 'type' => 'percent'],
            ],
            [],
            ['number', 'text', 'number', 'money', 'money', 'money', 'percent'],
        );

        $report['cards'] = $products->values()->map(function (array $row, int $i) {
            $margin = $this->margin($row['profit'], $row['revenue']);

            return [
                'title' => $row['name'],
                'highlight' => $i === 0,
                'badge' => $i === 0 ? 'Most profitable' : '#'.($i + 1),
                'stats' => [
                    ['label' => 'Product name', 'value' => $row['name'], 'type' => 'text'],
                    ['label' => 'Total quantity sold', 'value' => $row['qty'], 'type' => 'number'],
                    ['label' => 'Total sales amount', 'value' => $row['revenue'], 'type' => 'money'],
                    ['label' => 'Total purchase cost', 'value' => $row['cost'], 'type' => 'money'],
                    ['label' => 'Total profit', 'value' => $row['profit'], 'type' => 'money'],
                    ['label' => 'Profit margin %', 'value' => $margin, 'type' => 'percent'],
                ],
            ];
        })->all();

        return $report;
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

    /**
     * @param  array{q?:string,category_id?:int|string,brand_id?:int|string,flow?:string}  $filters
     */
    private function inventoryReport(Carbon $from, Carbon $to, array $meta, array $filters = []): array
    {
        $flow = in_array($filters['flow'] ?? 'all', ['in', 'out', 'all'], true) ? ($filters['flow'] ?? 'all') : 'all';
        $search = trim((string) ($filters['q'] ?? ''));
        $categoryId = $filters['category_id'] ?? null;
        $brandId = $filters['brand_id'] ?? null;

        $productQuery = Product::query()->with(['category', 'brand']);
        if ($search !== '') {
            $productQuery->where(function ($q) use ($search) {
                $q->where('name', 'like', '%'.$search.'%')
                    ->orWhere('sku', 'like', '%'.$search.'%')
                    ->orWhereHas('category', fn ($c) => $c->where('name', 'like', '%'.$search.'%'))
                    ->orWhereHas('brand', fn ($b) => $b->where('name', 'like', '%'.$search.'%'));
            });
        }
        if ($categoryId !== null && $categoryId !== '') {
            $productQuery->where('category_id', (int) $categoryId);
        }
        if ($brandId !== null && $brandId !== '') {
            $productQuery->where('brand_id', (int) $brandId);
        }

        $filteredIds = (clone $productQuery)->pluck('id');
        $hasProductFilter = $search !== '' || ($categoryId !== null && $categoryId !== '') || ($brandId !== null && $brandId !== '');
        if ($hasProductFilter && $filteredIds->isEmpty()) {
            return $this->pack($meta, ['Product name', 'SKU', 'Category', 'Brand', 'Opening stock', 'Stock in', 'Stock out', 'Product return', 'Supplier return', 'Damage / lost', 'Stock adjustment', 'Closing stock'], [], [
                'from' => $from->toDateString(), 'to' => $to->toDateString(),
            ]);
        }

        $ledger = InventoryLedger::query()
            ->with(['product.category', 'product.brand', 'warehouse', 'creator'])
            ->when($hasProductFilter, fn ($q) => $q->whereIn('product_id', $filteredIds))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $byProduct = $ledger->groupBy('product_id');
        $movedIds = $byProduct->keys()->filter()->all();
        $stockProducts = (clone $productQuery)
            ->where(function ($q) use ($movedIds) {
                $q->where('stock_on_hand', '>', 0);
                if ($movedIds !== []) {
                    $q->orWhereIn('id', $movedIds);
                }
            })
            ->orderBy('name')
            ->get();

        $summaryRows = [];
        $historyIn = [];
        $historyOut = [];
        $historyAll = [];

        foreach ($stockProducts as $product) {
            $moves = $byProduct->get($product->id, collect());
            $onHandMoves = $moves->filter(fn (InventoryLedger $row) => $row->affectsOnHand());
            $opening = (int) $onHandMoves->where('created_at', '<', $from)->sum('qty_delta');
            $periodMoves = $onHandMoves->filter(fn (InventoryLedger $row) => $row->created_at >= $from && $row->created_at <= $to);

            $stockIn = 0;
            $stockOut = 0;
            $productReturn = 0;
            $supplierReturn = 0;
            $damage = 0;
            $adjustment = 0;

            foreach ($periodMoves as $move) {
                $qty = (int) $move->qty_delta;
                match ($move->movementBucket()) {
                    'stock_in' => $stockIn += abs($qty),
                    'stock_out' => $stockOut += abs($qty),
                    'product_return' => $productReturn += abs($qty),
                    'supplier_return' => $supplierReturn += abs($qty),
                    'damage' => $damage += abs($qty),
                    'adjustment' => $adjustment += $qty,
                    default => null,
                };
            }

            $closing = $opening + (int) $periodMoves->sum('qty_delta');
            $hasActivity = $periodMoves->isNotEmpty() || $opening !== 0 || (int) $product->stock_on_hand !== 0;
            if (! $hasActivity) {
                continue;
            }

            $summaryRows[] = [
                $product->name,
                $product->sku,
                $product->category?->name ?: '—',
                $product->brand?->name ?: '—',
                $opening,
                $stockIn,
                $stockOut,
                $productReturn,
                $supplierReturn,
                $damage,
                $adjustment,
                $closing,
            ];

            foreach ($periodMoves as $move) {
                $bucket = $move->movementBucket();
                $incoming = in_array($bucket, ['stock_in', 'product_return'], true) || ($bucket === 'adjustment' && (int) $move->qty_delta > 0);
                $row = [
                    $move->created_at?->format('Y-m-d H:i'),
                    $product->name,
                    $move->warehouse?->name ?: '—',
                    $move->reasonLabel(),
                    (int) $move->qty_delta,
                    $move->creator?->name ?: 'System',
                    $move->reference ?: '—',
                ];
                $historyAll[] = $row;
                if ($incoming) {
                    $historyIn[] = $row;
                } else {
                    $historyOut[] = $row;
                }
            }
        }

        $historyAll = array_reverse($historyAll);
        $historyIn = array_reverse($historyIn);
        $historyOut = array_reverse($historyOut);

        $historyColumns = ['Date', 'Product', 'Warehouse', 'Reason', 'Quantity', 'Responsible person', 'Reference'];
        $historyTypes = ['text', 'text', 'text', 'text', 'number', 'text', 'text'];
        $historySections = match ($flow) {
            'in' => [[
                'title' => 'Stock in — movement details',
                'columns' => $historyColumns,
                'column_types' => $historyTypes,
                'rows' => $historyIn,
            ]],
            'out' => [[
                'title' => 'Stock out — movement details',
                'columns' => $historyColumns,
                'column_types' => $historyTypes,
                'rows' => $historyOut,
            ]],
            default => [
                [
                    'title' => 'Stock in — movement details',
                    'columns' => $historyColumns,
                    'column_types' => $historyTypes,
                    'rows' => $historyIn,
                ],
                [
                    'title' => 'Stock out — movement details',
                    'columns' => $historyColumns,
                    'column_types' => $historyTypes,
                    'rows' => $historyOut,
                ],
                [
                    'title' => 'Full movement history',
                    'columns' => $historyColumns,
                    'column_types' => $historyTypes,
                    'rows' => $historyAll,
                ],
            ],
        };

        return $this->pack(
            $meta,
            ['Product name', 'SKU', 'Category', 'Brand', 'Opening stock', 'Stock in', 'Stock out', 'Product return', 'Supplier return', 'Damage / lost', 'Stock adjustment', 'Closing stock'],
            $summaryRows,
            ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'flow' => $flow],
            [
                ['label' => 'Products', 'value' => count($summaryRows), 'type' => 'number'],
                ['label' => 'Stock in', 'value' => (int) collect($summaryRows)->sum(fn ($row) => (int) $row[5]), 'type' => 'number'],
                ['label' => 'Stock out', 'value' => (int) collect($summaryRows)->sum(fn ($row) => (int) $row[6]), 'type' => 'number'],
                ['label' => 'Product returns', 'value' => (int) collect($summaryRows)->sum(fn ($row) => (int) $row[7]), 'type' => 'number'],
                ['label' => 'Closing stock', 'value' => (int) collect($summaryRows)->sum(fn ($row) => (int) $row[11]), 'type' => 'number'],
            ],
            $historySections,
            ['text', 'text', 'text', 'text', 'number', 'number', 'number', 'number', 'number', 'number', 'number', 'number'],
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
        $sales = $this->salesmanPerformance($this->soldLines($from, $to))->keyBy('salesman_id');
        $rule = CommissionRule::activeDefault();
        $basis = $rule?->basis ?? CommissionRule::BASIS_COLLECTION;
        $basisLabel = match ($basis) {
            CommissionRule::BASIS_SALES => 'sales',
            CommissionRule::BASIS_PRODUCT => 'product sales',
            default => 'collection',
        };
        $defaultRate = match ($basis) {
            CommissionRule::BASIS_SALES => (float) ($rule?->sales_rate_percent ?? $rule?->collection_rate_percent ?? 0),
            CommissionRule::BASIS_PRODUCT => (float) ($rule?->product_rate_percent ?? $rule?->collection_rate_percent ?? 0),
            default => (float) ($rule?->collection_rate_percent ?? 0),
        };

        $commissions = Commission::query()
            ->with('salesman')
            ->where(function ($query) use ($from, $to) {
                $query->whereBetween('created_at', [$from, $to])
                    ->orWhereBetween('paid_at', [$from, $to]);
            })
            ->orderByDesc('created_at')
            ->get();

        $open = $commissions->where('status', '!=', Commission::STATUS_REJECTED);
        $bySalesman = $open->groupBy('salesman_id');

        $people = User::query()
            ->where('portal', User::PORTAL_SALESMAN)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $ids = $people->pluck('id')->merge($bySalesman->keys())->unique()->filter();
        $named = User::query()->whereIn('id', $ids)->get()->keyBy('id');

        $summaries = $ids->map(function ($id) use ($named, $people, $sales, $bySalesman, $defaultRate, $basisLabel) {
            $user = $named->get($id) ?? $people->firstWhere('id', $id);
            $name = $user?->name ?: 'Unassigned';
            $perf = $sales->get((int) $id);
            $rows = $bySalesman->get($id, collect());
            $earned = round((float) $rows->sum('commission_amount'), 2);
            $paid = round((float) $rows->where('status', Commission::STATUS_PAID)->sum('commission_amount'), 2);
            $due = round(max(0, $earned - $paid), 2);
            $avgRate = (float) $rows->avg('rate_percent');
            $rate = $avgRate > 0 ? $avgRate : $defaultRate;
            $rateLabel = $rate > 0
                ? rtrim(rtrim(number_format($rate, 2), '0'), '.').'% of '.$basisLabel
                : ($earned > 0 ? 'Fixed amount' : '—');
            $status = match (true) {
                $earned <= 0 && $paid <= 0 => 'Unpaid',
                $due <= 0.009 && $paid > 0 => 'Paid',
                $paid > 0.009 && $due > 0.009 => 'Partially paid',
                default => 'Unpaid',
            };
            $paidAt = $rows->where('status', Commission::STATUS_PAID)->max('paid_at');

            return [
                'salesman_id' => (int) $id,
                'salesman' => $name,
                'sales' => (float) ($perf['sales'] ?? 0),
                'orders' => (int) ($perf['orders'] ?? 0),
                'profit' => (float) ($perf['profit'] ?? 0),
                'rate' => $rate,
                'rate_label' => $rateLabel,
                'earned' => $earned,
                'paid' => $paid,
                'due' => $due,
                'status' => $status,
                'paid_at' => $paidAt ? Carbon::parse($paidAt)->toDateString() : '—',
            ];
        })->sortByDesc('earned')->values();

        $report = $this->pack(
            $meta,
            ['Salesman name', 'Total sales', 'Total orders', 'Total profit', 'Commission rate', 'Total commission', 'Paid commission', 'Due commission', 'Commission status', 'Payment date'],
            $summaries->map(fn (array $row) => [
                $row['salesman'],
                $row['sales'],
                $row['orders'],
                $row['profit'],
                $row['rate_label'],
                $row['earned'],
                $row['paid'],
                $row['due'],
                $row['status'],
                $row['paid_at'],
            ])->all(),
            ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'basis' => $basisLabel],
            [
                ['label' => 'Salesmen', 'value' => $summaries->count(), 'type' => 'number'],
                ['label' => 'Total commission', 'value' => (float) $summaries->sum('earned'), 'type' => 'money'],
                ['label' => 'Paid commission', 'value' => (float) $summaries->sum('paid'), 'type' => 'money'],
                ['label' => 'Due commission', 'value' => (float) $summaries->sum('due'), 'type' => 'money'],
                ['label' => 'Commission basis', 'value' => ucfirst($basisLabel), 'type' => 'text'],
            ],
            [
                [
                    'title' => 'Commission payment history',
                    'columns' => ['Date', 'Commission', 'Salesman', 'Type', 'Rate %', 'Amount', 'Status', 'Payment date'],
                    'column_types' => ['text', 'text', 'text', 'text', 'percent', 'money', 'text', 'text'],
                    'rows' => $commissions->map(fn (Commission $commission) => [
                        $commission->created_at?->format('Y-m-d'),
                        $commission->number,
                        $commission->salesman?->name ?: '—',
                        $commission->typeLabel(),
                        (float) $commission->rate_percent,
                        (float) $commission->commission_amount,
                        $commission->statusLabel(),
                        $commission->paid_at?->toDateString() ?: '—',
                    ])->all(),
                ],
            ],
            ['text', 'money', 'number', 'money', 'text', 'money', 'money', 'money', 'text', 'text'],
        );

        $report['cards'] = $summaries->values()->map(function (array $row, int $i) {
            return [
                'title' => $row['salesman'],
                'highlight' => $i === 0 && $row['earned'] > 0,
                'badge' => $row['status'],
                'stats' => [
                    ['label' => 'Salesman name', 'value' => $row['salesman'], 'type' => 'text'],
                    ['label' => 'Total sales', 'value' => $row['sales'], 'type' => 'money'],
                    ['label' => 'Total orders', 'value' => $row['orders'], 'type' => 'number'],
                    ['label' => 'Total profit', 'value' => $row['profit'], 'type' => 'money'],
                    ['label' => 'Commission rate', 'value' => $row['rate_label'], 'type' => 'text'],
                    ['label' => 'Total commission', 'value' => $row['earned'], 'type' => 'money'],
                    ['label' => 'Paid commission', 'value' => $row['paid'], 'type' => 'money'],
                    ['label' => 'Due commission', 'value' => $row['due'], 'type' => 'money'],
                    ['label' => 'Commission status', 'value' => $row['status'], 'type' => 'text'],
                    ['label' => 'Payment date', 'value' => $row['paid_at'], 'type' => 'text'],
                ],
            ];
        })->all();

        return $report;
    }

    /**
     * @param  array{q?:string,kind?:string,status?:string}  $filters
     */
    private function returnsReport(Carbon $from, Carbon $to, array $meta, array $filters = []): array
    {
        $kind = in_array($filters['kind'] ?? 'all', ['all', 'returns', 'warranty'], true) ? ($filters['kind'] ?? 'all') : 'all';
        $status = (string) ($filters['status'] ?? '');
        $search = trim((string) ($filters['q'] ?? ''));

        $returns = ProductReturn::query()
            ->with(['shop', 'order.salesman', 'invoice', 'items.product', 'creator', 'approver', 'restocker'])
            ->whereBetween('created_at', [$from, $to])
            ->when($kind === 'warranty', fn ($q) => $q->where('reason_type', ProductReturn::REASON_WARRANTY))
            ->when($kind === 'returns', fn ($q) => $q->where('reason_type', '!=', ProductReturn::REASON_WARRANTY))
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($inner) use ($search) {
                    $inner->where('number', 'like', '%'.$search.'%')
                        ->orWhereHas('shop', fn ($s) => $s->where('name', 'like', '%'.$search.'%')->orWhere('owner_name', 'like', '%'.$search.'%'))
                        ->orWhereHas('order', fn ($o) => $o->where('number', 'like', '%'.$search.'%'))
                        ->orWhereHas('invoice', fn ($i) => $i->where('number', 'like', '%'.$search.'%'))
                        ->orWhereHas('items', fn ($it) => $it->where('product_name', 'like', '%'.$search.'%')->orWhere('product_sku', 'like', '%'.$search.'%'));
                });
            })
            ->latest()
            ->get();

        if ($status !== '') {
            $returns = $returns->filter(function (ProductReturn $return) use ($status) {
                $label = strtolower($return->handlingStatusLabel());

                return match ($status) {
                    'pending' => $return->status === ProductReturn::STATUS_PENDING,
                    'approved' => in_array($return->status, [ProductReturn::STATUS_APPROVED, ProductReturn::STATUS_RESTOCKED], true),
                    'repaired' => $return->repair_status === ProductReturn::REPAIR_DONE,
                    'replaced' => (bool) $return->replaced,
                    'refunded' => $return->credit_issued || (float) $return->refund_amount > 0,
                    default => $label === strtolower($status),
                };
            })->values();
        }

        $detailRows = [];
        $warrantyRows = [];
        $expiring = 0;
        $byProduct = [];

        foreach ($returns as $return) {
            foreach ($return->items as $item) {
                $product = $item->product;
                $warrantyText = $product?->warranty ?: '—';
                $start = $return->order?->submitted_at;
                $expiry = Warranty::expiresAt($product?->warranty, $start);
                $warrantyStatus = Warranty::status($product?->warranty, $start, $return->created_at, $return->isWarrantyClaim());
                if ($expiry && $expiry->between(now(), now()->addDays(30))) {
                    $expiring++;
                }

                $row = [
                    $item->product_name,
                    (int) $item->quantity,
                    $return->order?->number ?: '—',
                    $return->invoice?->number ?: ($return->order?->invoice?->number ?: '—'),
                    $return->shop?->name ?: '—',
                    $return->created_at?->toDateString(),
                    $return->reasonTypeLabel(),
                    is_string($product?->warranty) && $product->warranty !== '' ? $product->warranty : '—',
                    $expiry?->toDateString() ?: '—',
                    $warrantyStatus,
                    $return->replaced ? 'Yes' : 'No',
                    $return->repairStatusLabel(),
                    (float) ($return->credit_issued ? ($item->line_total ?: $return->refund_amount) : ($return->refund_amount > 0 ? $item->line_total : 0)),
                    $return->order?->salesman?->name ?: ($return->creator?->name ?: '—'),
                    $return->inventoryConditionLabel(),
                    $return->handlingStatusLabel(),
                    $return->number,
                ];
                $detailRows[] = $row;
                if ($return->isWarrantyClaim()) {
                    $warrantyRows[] = $row;
                }

                $pid = $item->product_id ?: 'sku-'.$item->product_sku;
                $byProduct[$pid] ??= ['name' => $item->product_name, 'sku' => $item->product_sku, 'qty' => 0, 'amount' => 0.0, 'warranty' => 0];
                $byProduct[$pid]['qty'] += (int) $item->quantity;
                $byProduct[$pid]['amount'] += (float) $item->line_total;
                if ($return->isWarrantyClaim()) {
                    $byProduct[$pid]['warranty'] += (int) $item->quantity;
                }
            }
        }

        $sold = $this->productRollup($this->soldLines($from, $to))->keyBy('product_id');
        $rateRows = collect($byProduct)->map(function (array $row, $id) use ($sold) {
            $soldQty = (is_numeric($id) && isset($sold[(int) $id]['qty'])) ? (int) $sold[(int) $id]['qty'] : 0;
            $rate = $soldQty > 0 ? round(($row['qty'] / $soldQty) * 100, 1) : ($row['qty'] > 0 ? 100 : 0);

            return [$row['name'], $row['sku'], $row['qty'], $soldQty, $row['amount'], $row['warranty'], $rate];
        })->sortByDesc(fn ($row) => $row[2])->values();

        $detailCols = ['Product name', 'Qty', 'Order ID', 'Invoice', 'Customer / shop', 'Return date', 'Return reason', 'Warranty period', 'Warranty expiry', 'Warranty status', 'Replacement', 'Repair status', 'Refund amount', 'Responsible person', 'Inventory update', 'Status', 'Return #'];
        $detailTypes = ['text', 'number', 'text', 'text', 'text', 'text', 'text', 'text', 'text', 'text', 'text', 'text', 'money', 'text', 'text', 'text', 'text'];

        return $this->pack(
            $meta,
            $detailCols,
            $detailRows,
            ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'sum' => (float) $returns->sum('total')],
            [
                ['label' => 'Return lines', 'value' => count($detailRows), 'type' => 'number'],
                ['label' => 'Warranty claims', 'value' => count($warrantyRows), 'type' => 'number'],
                ['label' => 'Refunded', 'value' => (float) $returns->sum(fn (ProductReturn $r) => (float) ($r->refund_amount ?: ($r->credit_issued ? $r->total : 0))), 'type' => 'money'],
                ['label' => 'Replaced', 'value' => $returns->where('replaced', true)->count(), 'type' => 'number'],
                ['label' => 'Warranty expiring (30 days)', 'value' => $expiring, 'type' => 'number'],
            ],
            [
                [
                    'title' => 'Warranty claims',
                    'columns' => $detailCols,
                    'column_types' => $detailTypes,
                    'rows' => $warrantyRows,
                ],
                [
                    'title' => 'Product-wise return rate & warranty claims',
                    'columns' => ['Product', 'SKU', 'Returned qty', 'Sold qty', 'Refund amount', 'Warranty qty', 'Return rate %'],
                    'column_types' => ['text', 'text', 'number', 'number', 'money', 'number', 'percent'],
                    'rows' => $rateRows->all(),
                ],
            ],
            $detailTypes,
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
