<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ReportController extends Controller
{
    public function __construct(private ReportService $reports) {}

    public function index(Request $request)
    {
        abort_unless($request->user()->can('reports.view'), 403);

        [$from, $to] = $this->period($request);

        return view('admin.reports.index', [
            'catalog' => $this->reports->catalog(),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ]);
    }

    public function show(Request $request, string $report)
    {
        abort_unless($request->user()->can('reports.view'), 403);

        [$from, $to] = $this->period($request);
        $filters = $this->filters($request);

        return view('admin.reports.show', [
            'key' => $report,
            'report' => $this->reports->generate($report, $from, $to, $filters),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'catalog' => $this->reports->catalog(),
            'filters' => $filters,
            'categories' => $report === 'inventory' ? Category::query()->orderBy('name')->get() : collect(),
            'brands' => $report === 'inventory' ? Brand::query()->orderBy('name')->get() : collect(),
        ]);
    }

    public function export(Request $request, string $report)
    {
        abort_unless($request->user()->can('reports.export'), 403);

        [$from, $to] = $this->period($request);
        $format = strtolower((string) $request->input('format', 'csv'));

        return $this->reports->download($report, $from, $to, $this->filters($request), $format);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function period(Request $request): array
    {
        $from = $request->filled('from')
            ? Carbon::parse($request->input('from'))->startOfDay()
            : now()->startOfMonth();
        $to = $request->filled('to')
            ? Carbon::parse($request->input('to'))->endOfDay()
            : now()->endOfDay();

        if ($from->gt($to)) {
            return [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        return [$from, $to];
    }

    /**
     * @return array{q:string,category_id:?string,brand_id:?string,flow:string,kind:string,status:string}
     */
    private function filters(Request $request): array
    {
        $flow = (string) $request->input('flow', 'all');
        if (! in_array($flow, ['all', 'in', 'out'], true)) {
            $flow = 'all';
        }
        $kind = (string) $request->input('kind', 'all');
        if (! in_array($kind, ['all', 'returns', 'warranty'], true)) {
            $kind = 'all';
        }

        return [
            'q' => trim((string) $request->input('q', '')),
            'category_id' => $request->filled('category_id') ? (string) $request->input('category_id') : null,
            'brand_id' => $request->filled('brand_id') ? (string) $request->input('brand_id') : null,
            'flow' => $flow,
            'kind' => $kind,
            'status' => (string) $request->input('status', ''),
        ];
    }
}
