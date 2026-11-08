<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\DueManagementService;
use Illuminate\Http\Request;

class DueManagementController extends Controller
{
    public function __construct(private DueManagementService $dues) {}

    public function index(Request $request)
    {
        abort_unless($request->user()->can('dues.view'), 403);

        $filters = $request->only(['shop', 'salesman_id', 'min_outstanding', 'from', 'to']);
        $rows = $this->dues->outstandingRows($filters, $request->user());
        $bySalesman = $this->dues->bySalesman($rows);
        $salesmen = User::query()
            ->where('portal', User::PORTAL_SALESMAN)
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('admin.dues.index', [
            'rows' => $rows,
            'bySalesman' => $bySalesman,
            'salesmen' => $salesmen,
            'filters' => $filters,
            'totalOutstanding' => round((float) $rows->sum('outstanding'), 2),
            'highest' => $rows->take(10),
        ]);
    }

    public function export(Request $request)
    {
        abort_unless($request->user()->can('dues.export'), 403);

        $filters = $request->only(['shop', 'salesman_id', 'min_outstanding', 'from', 'to']);
        $rows = $this->dues->outstandingRows($filters, $request->user());
        $format = $request->get('format', 'csv');

        if ($format === 'pdf') {
            return $this->dues->exportPdf($rows);
        }

        return $this->dues->exportCsv($rows);
    }
}
