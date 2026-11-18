<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopVisit;
use App\Services\FieldActivityService;
use Illuminate\Http\Request;

class VisitController extends Controller
{
    public function __construct(private FieldActivityService $activity) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', ShopVisit::class);

        $period = array_key_exists((string) $request->query('period'), FieldActivityService::PERIODS) ? $request->query('period') : 'today';
        $from = $this->activity->periodStart($period);

        $visits = ShopVisit::query()
            ->with(['shop', 'salesman', 'order'])
            ->when($from, fn ($q) => $q->where('checked_in_at', '>=', $from))
            ->when($request->salesman_id, fn ($q, $id) => $q->where('salesman_id', $id))
            ->when($request->shop_id, fn ($q, $id) => $q->where('shop_id', $id))
            ->when($request->outcome, fn ($q, $outcome) => $q->where('outcome', $outcome))
            ->latest('checked_in_at')
            ->paginate(20)
            ->withQueryString();

        $summary = $this->activity->summary($from);

        return view('admin.visits.index', [
            'visits' => $visits,
            'summary' => $summary,
            'totals' => [
                'visits' => $summary->sum('visits'),
                'shops' => $this->activity->distinctShopsVisited($from),
                'with_order' => $summary->sum('with_order'),
                'orders' => $summary->sum('orders'),
                'order_value' => $summary->sum('order_value'),
                'added' => $summary->sum('added'),
                'added_pending' => $summary->sum('added_pending'),
                'active' => $summary->where('visits', '>', 0)->count(),
            ],
            'period' => $period,
            'periods' => FieldActivityService::PERIODS,
            'outcomes' => [
                ShopVisit::OUTCOME_ORDER_TAKEN => 'Order taken',
                ShopVisit::OUTCOME_NO_ORDER => 'No order',
                ShopVisit::OUTCOME_CLOSED => 'Shop closed',
                ShopVisit::OUTCOME_FOLLOW_UP => 'Follow-up needed',
                ShopVisit::OUTCOME_CANCELLED => 'Opened by mistake',
                ShopVisit::OUTCOME_IN_PROGRESS => 'In progress',
            ],
        ]);
    }
}
