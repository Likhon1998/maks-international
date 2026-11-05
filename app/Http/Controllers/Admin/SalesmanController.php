<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\Territory;
use App\Models\User;
use App\Models\AuditLog;
use App\Services\DeliveryStockService;
use App\Services\FieldActivityService;
use App\Services\RbacService;
use App\Services\SalesmanService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SalesmanController extends Controller
{
    public function __construct(
        private SalesmanService $salesmen,
        private FieldActivityService $activity,
        private DeliveryStockService $deliveryStock,
        private RbacService $rbac,
    ) {}

    public function index(Request $request)
    {
        abort_unless($request->user()->can('salesmen.view'), 403);

        $salesmen = $this->salesmen->salesmanQuery()
            ->when($request->search, function ($q, $search) {
                $q->where(function ($inner) use ($search) {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhereHas('salesmanProfile', fn ($p) => $p->where('employee_code', 'like', "%{$search}%"));
                });
            })
            ->when($request->territory_id, function ($q, $territoryId) {
                $q->whereHas('salesmanProfile', fn ($p) => $p->where('territory_id', $territoryId));
            })
            ->latest()
            ->paginate(15)
            ->withQueryString();

        $territories = Territory::query()->where('is_active', true)->orderBy('name')->get();

        return view('admin.salesmen.index', compact('salesmen', 'territories'));
    }

    public function create(Request $request)
    {
        abort_unless($request->user()->can('salesmen.create'), 403);

        return view('admin.salesmen.create', $this->formData());
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->can('salesmen.create'), 403);

        $data = $this->validated($request);
        $salesman = $this->salesmen->create($data, $request->input('shop_ids', []), $request->user());

        return redirect()->route('salesmen.show', $salesman)->with('success', 'Salesman created. Field login is ready.');
    }

    public function show(Request $request, User $salesman)
    {
        abort_unless($request->user()->can('salesmen.view'), 403);
        abort_unless($salesman->portal === User::PORTAL_SALESMAN, 404);

        $salesman->load([
            'salesmanProfile.territory',
            'assignedShops.territory',
            'visits' => fn ($q) => $q->with(['shop', 'order'])->latest('checked_in_at')->limit(10),
            'salesmanOrders' => fn ($q) => $q->latest()->limit(10),
            'createdShops' => fn ($q) => $q->latest()->limit(20),
        ]);

        $monthOrders = $salesman->salesmanOrders()
            ->whereMonth('submitted_at', now()->month)
            ->whereYear('submitted_at', now()->year)
            ->sum('total');

        $activity = [
            'today' => $this->activity->summary($this->activity->periodStart('today'), $salesman->id)->first(),
            'month' => $this->activity->summary($this->activity->periodStart('month'), $salesman->id)->first(),
            'all' => $this->activity->summary(null, $salesman->id)->first(),
        ];

        $stock = $this->deliveryStock->salesmanBalances($salesman);
        $assignedDeliveries = \App\Models\Delivery::query()
            ->with(['shop', 'order'])
            ->where('assigned_to', $salesman->id)
            ->latest('dispatched_at')
            ->limit(15)
            ->get();

        return view('admin.salesmen.show', compact('salesman', 'monthOrders', 'activity', 'stock', 'assignedDeliveries'));
    }

    public function edit(Request $request, User $salesman)
    {
        abort_unless($request->user()->can('salesmen.edit'), 403);
        abort_unless($salesman->portal === User::PORTAL_SALESMAN, 404);

        $salesman->load(['salesmanProfile', 'assignedShops']);

        return view('admin.salesmen.edit', array_merge($this->formData(), compact('salesman')));
    }

    public function update(Request $request, User $salesman)
    {
        abort_unless($request->user()->can('salesmen.edit'), 403);
        abort_unless($salesman->portal === User::PORTAL_SALESMAN, 404);

        $data = $this->validated($request, $salesman);
        $this->salesmen->update($salesman, $data, $request->input('shop_ids', []), $request->user());

        return redirect()->route('salesmen.show', $salesman)->with('success', 'Salesman updated.');
    }

    public function resetPassword(Request $request, User $salesman)
    {
        abort_unless($request->user()->can('users.reset_password') || $request->user()->can('salesmen.edit'), 403);
        abort_unless($salesman->portal === User::PORTAL_SALESMAN, 404);

        $temporary = Str::password(12);
        $this->rbac->resetPassword($salesman, $temporary, $request->user());

        return back()->with('success', 'Temporary password generated (shown once): '.$temporary);
    }

    public function loginLogs(Request $request, User $salesman)
    {
        abort_unless($request->user()->can('salesmen.view'), 403);
        abort_unless($salesman->portal === User::PORTAL_SALESMAN, 404);

        $logs = AuditLog::query()
            ->where('user_id', $salesman->id)
            ->where(function ($q) {
                $q->where('action', 'like', '%login%')
                    ->orWhere('module', 'auth')
                    ->orWhere('description', 'like', '%logged in%');
            })
            ->latest()
            ->paginate(40);

        return view('admin.salesmen.login-logs', compact('salesman', 'logs'));
    }

    private function formData(): array
    {
        return [
            'territories' => Territory::query()->where('is_active', true)->orderBy('name')->get(),
            'shops' => Shop::query()
                ->with('assignedSalesman:id,name')
                ->where('status', '!=', Shop::STATUS_REJECTED)
                ->orderBy('city')
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'status', 'city', 'assigned_salesman_id']),
        ];
    }

    private function validated(Request $request, ?User $salesman = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($salesman?->id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => [$salesman ? 'nullable' : 'required', 'string', 'min:8'],
            'employee_code' => ['nullable', 'string', 'max:40', Rule::unique('salesman_profiles', 'employee_code')->ignore($salesman?->salesmanProfile?->id)],
            'territory_id' => ['nullable', 'exists:territories,id'],
            'monthly_target' => ['nullable', 'numeric', 'min:0'],
            'joined_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'grade' => ['nullable', 'string', 'max:20'],
            'rating' => ['nullable', 'numeric', 'min:0', 'max:5'],
            'shop_ids' => ['nullable', 'array'],
            'shop_ids.*' => ['integer', 'exists:shops,id'],
        ]) + [
            'is_active' => $request->boolean('is_active'),
        ];
    }
}
