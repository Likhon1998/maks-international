<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Shop;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DueManagementService
{
    public function __construct(private CreditService $credit) {}

    /**
     * @param  array{shop?:string,salesman_id?:int|string,min_outstanding?:float|string,from?:string,to?:string}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function outstandingRows(array $filters = [], ?User $viewer = null): Collection
    {
        // Repair stale denormalized balances before listing dues.
        $this->credit->syncActiveDues();

        $query = Shop::query()
            ->with(['assignedSalesman'])
            ->when($viewer, fn ($q) => $q->visibleTo($viewer))
            ->where('outstanding_balance', '>', 0)
            ->orderByDesc('outstanding_balance');

        if (! empty($filters['shop'])) {
            $term = '%'.$filters['shop'].'%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('code', 'like', $term)
                    ->orWhere('owner_name', 'like', $term);
            });
        }

        if (! empty($filters['salesman_id'])) {
            $query->where('assigned_salesman_id', (int) $filters['salesman_id']);
        }

        if (isset($filters['min_outstanding']) && $filters['min_outstanding'] !== '' && $filters['min_outstanding'] !== null) {
            $query->where('outstanding_balance', '>=', (float) $filters['min_outstanding']);
        }

        // Date range limits which shops appear: shops with invoice or collection activity in the period,
        // while Outstanding still shows the current open balance for those shops.
        if (! empty($filters['from']) || ! empty($filters['to'])) {
            $from = $filters['from'] ?? null;
            $to = $filters['to'] ?? null;
            $query->where(function ($q) use ($from, $to) {
                $q->whereHas('invoices', function ($invoice) use ($from, $to) {
                    $invoice->whereNotIn('status', [Invoice::STATUS_DRAFT, Invoice::STATUS_VOID])
                        ->when($from, fn ($iq) => $iq->whereDate('issued_at', '>=', $from))
                        ->when($to, fn ($iq) => $iq->whereDate('issued_at', '<=', $to));
                })->orWhereHas('payments', function ($payment) use ($from, $to) {
                    $payment->where('status', Payment::STATUS_VERIFIED)
                        ->when($from, fn ($pq) => $pq->whereDate('verified_at', '>=', $from))
                        ->when($to, fn ($pq) => $pq->whereDate('verified_at', '<=', $to));
                });
            });
        }

        $shops = $query->get();

        $shopIds = $shops->pluck('id');

        $lastPayments = Payment::query()
            ->whereIn('shop_id', $shopIds)
            ->where('status', Payment::STATUS_VERIFIED)
            ->orderByDesc('verified_at')
            ->orderByDesc('id')
            ->get()
            ->unique('shop_id')
            ->keyBy('shop_id');

        $oldestOpen = Invoice::query()
            ->whereIn('shop_id', $shopIds)
            ->whereIn('status', [Invoice::STATUS_ISSUED, Invoice::STATUS_PARTIAL])
            ->selectRaw('shop_id, MIN(COALESCE(due_at, issued_at, created_at)) as oldest_at')
            ->groupBy('shop_id')
            ->pluck('oldest_at', 'shop_id');

        $openInvoiceTotals = Invoice::query()
            ->whereIn('shop_id', $shopIds)
            ->whereIn('status', [Invoice::STATUS_ISSUED, Invoice::STATUS_PARTIAL])
            ->selectRaw('shop_id, SUM(balance) as open_balance, COUNT(*) as open_count')
            ->groupBy('shop_id')
            ->get()
            ->keyBy('shop_id');

        $salesTotals = Invoice::query()
            ->whereIn('shop_id', $shopIds)
            ->whereNotIn('status', [Invoice::STATUS_DRAFT, Invoice::STATUS_VOID])
            ->when(! empty($filters['from']), fn ($q) => $q->whereDate('issued_at', '>=', $filters['from']))
            ->when(! empty($filters['to']), fn ($q) => $q->whereDate('issued_at', '<=', $filters['to']))
            ->selectRaw('shop_id, SUM(total) as sales_total')
            ->groupBy('shop_id')
            ->pluck('sales_total', 'shop_id');

        $collectionTotals = Payment::query()
            ->whereIn('shop_id', $shopIds)
            ->where('status', Payment::STATUS_VERIFIED)
            ->when(! empty($filters['from']), fn ($q) => $q->whereDate('verified_at', '>=', $filters['from']))
            ->when(! empty($filters['to']), fn ($q) => $q->whereDate('verified_at', '<=', $filters['to']))
            ->selectRaw('shop_id, SUM(amount) as collected')
            ->groupBy('shop_id')
            ->pluck('collected', 'shop_id');

        return $shops->map(function (Shop $shop) use ($lastPayments, $oldestOpen, $salesTotals, $collectionTotals, $openInvoiceTotals) {
            $last = $lastPayments->get($shop->id);
            $oldest = $oldestOpen->get($shop->id);
            $open = $openInvoiceTotals->get($shop->id);
            $days = 0;
            if ($oldest) {
                $days = (int) \Illuminate\Support\Carbon::parse($oldest)->startOfDay()->diffInDays(now()->startOfDay());
            }

            return [
                'shop' => $shop,
                'salesman' => $shop->assignedSalesman,
                'outstanding' => (float) $shop->outstanding_balance,
                'open_invoice_balance' => (float) ($open->open_balance ?? 0),
                'open_invoice_count' => (int) ($open->open_count ?? 0),
                'last_payment_amount' => $last ? (float) $last->amount : 0.0,
                'last_payment_date' => $last?->verified_at,
                'days_outstanding' => $days,
                'total_sales' => (float) ($salesTotals[$shop->id] ?? 0),
                'total_collections' => (float) ($collectionTotals[$shop->id] ?? 0),
            ];
        })->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array{salesman: ?User, outstanding: float, shops: int}>
     */
    public function bySalesman(Collection $rows): Collection
    {
        return $rows->groupBy(fn ($row) => $row['salesman']?->id ?? 0)
            ->map(function (Collection $group) {
                /** @var User|null $salesman */
                $salesman = $group->first()['salesman'] ?? null;

                return [
                    'salesman' => $salesman,
                    'outstanding' => round((float) $group->sum('outstanding'), 2),
                    'shops' => $group->count(),
                ];
            })
            ->sortByDesc('outstanding')
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    public function exportCsv(Collection $rows): StreamedResponse
    {
        $filename = 'due-management-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'Shop code', 'Shop name', 'Salesman', 'Outstanding', 'Open invoices', 'Last payment', 'Last payment date',
                'Days outstanding', 'Total sales', 'Total collections',
            ]);
            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['shop']->code,
                    $row['shop']->name,
                    $row['salesman']?->name ?? 'Unassigned',
                    number_format($row['outstanding'], 2, '.', ''),
                    (int) ($row['open_invoice_count'] ?? 0),
                    number_format($row['last_payment_amount'], 2, '.', ''),
                    $row['last_payment_date']?->format('Y-m-d') ?? '',
                    $row['days_outstanding'],
                    number_format($row['total_sales'], 2, '.', ''),
                    number_format($row['total_collections'], 2, '.', ''),
                ]);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    public function exportPdf(Collection $rows)
    {
        $pdf = Pdf::loadView('admin.dues.document', [
            'rows' => $rows,
            'generatedAt' => now(),
            'totalOutstanding' => round((float) $rows->sum('outstanding'), 2),
        ])->setPaper('a4', 'landscape');

        return $pdf->download('due-management-'.now()->format('Ymd-His').'.pdf');
    }
}
