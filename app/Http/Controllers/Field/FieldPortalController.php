<?php

namespace App\Http\Controllers\Field;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Commission;
use App\Models\CommissionRule;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\PartnerInquiry;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Reward;
use App\Models\SalesTarget;
use App\Models\Shop;
use App\Models\ShopVisit;
use App\Models\Territory;
use App\Models\User;
use App\Services\SalaryService;
use App\Rules\BangladeshPhone;
use App\Services\AppNotificationService;
use App\Services\CollectionSummaryService;
use App\Services\DeliveryStockService;
use App\Services\FulfilmentService;
use App\Services\InvoiceService;
use App\Services\OrderService;
use App\Services\PartnerInquiryService;
use App\Services\PaymentService;
use App\Services\BangladeshGeoService;
use App\Services\ShopService;
use App\Services\TargetService;
use App\Services\VisitService;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class FieldPortalController extends Controller
{
    private const COUNTED_STATUSES_EXCLUDED = [Order::STATUS_REJECTED, Order::STATUS_CANCELLED, Order::STATUS_DRAFT];

    public const SHOP_CATEGORIES = ['Mobile phones', 'Accessories', 'Electronics', 'Computers', 'Home appliances', 'Grocery', 'Pharmacy', 'Other'];

    public const SHOP_INTEREST = ['hot' => 'Ready to order', 'warm' => 'Interested', 'cold' => 'Just looking'];

    public function __construct(
        private VisitService $visits,
        private OrderService $orders,
        private ShopService $shops,
        private AppNotificationService $notifications,
        private PartnerInquiryService $partnerInquiries,
        private TargetService $targets,
        private SalaryService $salaries,
        private PaymentService $payments,
        private FulfilmentService $fulfilment,
        private DeliveryStockService $deliveryStock,
        private CollectionSummaryService $collections,
        private BangladeshGeoService $geo,
    ) {}

    public function dashboard(Request $request)
    {
        $user = $request->user();

        $monthOrders = Order::query()
            ->where('salesman_id', $user->id)
            ->whereNotIn('status', self::COUNTED_STATUSES_EXCLUDED)
            ->whereBetween('submitted_at', [now()->startOfMonth(), now()->endOfMonth()]);

        $monthTotal = (float) (clone $monthOrders)->sum('total');
        $todayOrders = (clone $monthOrders)->whereDate('submitted_at', today());

        $salesTarget = SalesTarget::query()
            ->where('salesman_id', $user->id)
            ->where('year', now()->year)
            ->where('month', now()->month)
            ->first();
        $target = (float) ($salesTarget?->target_amount ?? $user->salesmanProfile?->monthly_target ?? 0);
        // Same rule as targets and rewards: delivered this month minus approved returns.
        $achieved = $this->targets->achievedAmount($user->id, now()->startOfMonth(), now()->endOfMonth());

        $shops = $this->shopsWithVisits($user);
        $openVisit = $this->openVisit($user);

        $weekTotals = Order::query()
            ->where('salesman_id', $user->id)
            ->whereNotIn('status', self::COUNTED_STATUSES_EXCLUDED)
            ->where('submitted_at', '>=', today()->subDays(6))
            ->selectRaw('DATE(submitted_at) as day, SUM(total) as total')
            ->groupBy('day')
            ->pluck('total', 'day');
        $trend = collect(range(6, 0))->map(function (int $ago) use ($weekTotals) {
            $day = today()->subDays($ago);

            return ['label' => $ago === 0 ? 'Today' : $day->format('D'), 'total' => (float) ($weekTotals[$day->toDateString()] ?? 0)];
        });

        return view('field.dashboard', [
            'trend' => $trend,
            'routeShops' => $shops->sortBy(fn (Shop $s) => [$openVisit?->shop_id === $s->id ? 0 : 1, $s->visited_today ? 1 : 0, $s->name])->values(),
            'monthTotal' => $monthTotal,
            'target' => $target,
            'achieved' => $achieved,
            'progress' => $target > 0 ? min(100, round($achieved / $target * 100)) : null,
            'daysLeft' => (int) now()->diffInDays(now()->endOfMonth()) + 1,
            'todayVisits' => ShopVisit::query()->where('salesman_id', $user->id)->whereDate('checked_in_at', today())->count(),
            'visitWeek' => $this->visitDayLog($user, today()->subDays(6), true)->values(),
            'todayVisitList' => ShopVisit::query()->with('shop')
                ->where('salesman_id', $user->id)
                ->whereDate('checked_in_at', today())
                ->latest('checked_in_at')
                ->get(),
            'todayAddedList' => Shop::query()
                ->where('created_by', $user->id)
                ->whereDate('created_at', today())
                ->latest()
                ->get(),
            'todayAddedCount' => Shop::query()->where('created_by', $user->id)->whereDate('created_at', today())->count(),
            'todayOrderCount' => (clone $todayOrders)->count(),
            'todayOrderTotal' => (float) (clone $todayOrders)->sum('total'),
            'waitingApproval' => Order::query()->where('salesman_id', $user->id)
                ->whereIn('status', [Order::STATUS_PENDING_AUDIT, Order::STATUS_AWAITING_ADVANCE])->count(),
            'monthCommission' => (float) Commission::query()->where('salesman_id', $user->id)
                ->where('year', now()->year)->where('month', now()->month)
                ->where('status', '!=', Commission::STATUS_REJECTED)->sum('commission_amount'),
            'openVisit' => $openVisit,
            'toVisit' => $shops->reject(fn ($s) => $s->visited_today)->take(5),
            'shopCount' => $shops->count(),
            'visitedCount' => $shops->where('visited_today', true)->count(),
            'recentOrders' => Order::query()->with('shop')->where('salesman_id', $user->id)
                ->latest('submitted_at')->limit(6)->get(),
            'shopsAddedMonth' => Shop::query()->where('created_by', $user->id)->where('created_at', '>=', now()->startOfMonth())->count(),
            'shopsPending' => Shop::query()->where('created_by', $user->id)->where('status', Shop::STATUS_PENDING)->count(),
            'loginsToCreate' => $this->loginsToCreate($user),
            'shopsOutstanding' => (float) $shops->sum('outstanding_balance'),
            'targetMet' => $salesTarget?->target_met || ($target > 0 && $achieved >= $target),
        ]);
    }

    public function visitsLog(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $fromInput = $data['from'] ?? null;
        $toInput = $data['to'] ?? null;
        if ($fromInput && ! $toInput) {
            $toInput = $fromInput;
        } elseif ($toInput && ! $fromInput) {
            $fromInput = $toInput;
        }

        $filtered = (bool) ($fromInput || $toInput);
        $from = $fromInput ? Carbon::parse($fromInput)->startOfDay() : now()->subDays(29)->startOfDay();
        $to = $toInput ? Carbon::parse($toInput)->endOfDay() : now()->endOfDay();
        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        $days = $this->visitDayLog($user, $from, false, $to);
        if (! $filtered && ! $days->has(today()->toDateString())) {
            $days = collect([
                today()->toDateString() => [
                    'date' => today(),
                    'visits' => 0,
                    'shops' => 0,
                    'rows' => collect(),
                ],
            ])->merge($days);
        }

        $rows = $days->flatMap(fn ($day) => $day['rows']);

        return view('field.visits', [
            'days' => $days,
            'shopTotal' => $rows->unique('shop_id')->count(),
            'visitTotal' => $rows->count(),
            'openVisit' => $this->openVisit($user),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'filtered' => $filtered,
            'singleDay' => $from->isSameDay($to),
        ]);
    }

    public function shops(Request $request)
    {
        $user = $request->user();
        $shops = $this->catalogShops($user);
        $mineCount = $shops->where('is_mine', true)->count();

        return view('field.shops', [
            'shops' => $shops,
            'mineCount' => $mineCount,
            'otherCount' => $shops->count() - $mineCount,
            'areaCounts' => $shops->groupBy('area_label')->map->count()->sortDesc(),
            'openVisit' => $this->openVisit($user),
            'addedShops' => Shop::query()->withCount('users')->where('created_by', $user->id)->latest()->limit(50)->get(),
        ]);
    }

    public function createShop(Request $request)
    {
        return view('field.shop-create', [
            'openVisit' => $this->openVisit($request->user()),
            'categories' => self::SHOP_CATEGORIES,
            'interests' => self::SHOP_INTEREST,
            'suggestedPassword' => Str::password(10, symbols: false),
        ]);
    }

    public function geoDivisions(): \Illuminate\Http\JsonResponse
    {
        return response()->json(['data' => $this->geo->divisions()]);
    }

    public function geoDistricts(Request $request): \Illuminate\Http\JsonResponse
    {
        return response()->json(['data' => $this->geo->districts((string) $request->query('division', ''))]);
    }

    public function geoThanas(Request $request): \Illuminate\Http\JsonResponse
    {
        return response()->json(['data' => $this->geo->thanas((string) $request->query('district', ''))]);
    }

    public function geoPostOffices(Request $request): \Illuminate\Http\JsonResponse
    {
        return response()->json(['data' => $this->geo->postOffices((string) $request->query('district', ''), (string) $request->query('thana', ''))]);
    }

    public function geoLocations(Request $request): \Illuminate\Http\JsonResponse
    {
        return response()->json(['data' => $this->geo->locations((string) $request->query('thana', ''), $request->query('post_office'))]);
    }

    public function storeShop(Request $request)
    {
        $user = $request->user();
        $createLogin = $request->boolean('create_login');

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:160'],
            'owner_name' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', new BangladeshPhone],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:80'],
            'division' => ['nullable', 'string', 'max:80'],
            'district' => ['nullable', 'string', 'max:80'],
            'thana' => ['nullable', 'string', 'max:80'],
            'post_office' => ['nullable', 'string', 'max:120'],
            'location' => ['nullable', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'sells' => ['nullable', 'array', 'max:8'],
            'sells.*' => ['string', Rule::in(self::SHOP_CATEGORIES)],
            'interest' => ['nullable', Rule::in(array_keys(self::SHOP_INTEREST))],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'login_email' => [$createLogin ? 'required' : 'nullable', 'email', 'max:180'],
            'login_password' => [$createLogin ? 'required' : 'nullable', 'string', 'min:8', 'max:64'],
        ], [
            'login_email.required' => 'Enter the owner\'s email for portal login.',
            'login_password.required' => 'Set a password the owner can use to sign in.',
            'login_password.min' => 'Use at least 8 characters so the account is safe.',
        ]);

        if (! empty($data['phone'])) {
            $data['phone'] = BangladeshPhone::normalize($data['phone']);
            $existing = Shop::query()->where('phone', $data['phone'])->first();
            if ($existing) {
                throw ValidationException::withMessages([
                    'phone' => "This phone number already belongs to {$existing->name} ({$existing->code}).",
                ]);
            }
        }

        $data['name'] = trim((string) ($data['name'] ?? '')) ?: 'New shop '.$this->shops->nextCode();
        $data['owner_name'] = trim((string) ($data['owner_name'] ?? ''));
        foreach (['division', 'district', 'thana', 'post_office', 'location'] as $geoKey) {
            $data[$geoKey] = trim((string) ($data[$geoKey] ?? '')) ?: null;
        }
        if (! empty($data['thana'])) {
            $data['city'] = $data['thana'];
        } elseif (! empty($data['district'])) {
            $data['city'] = $data['district'];
        }
        if (! empty($data['location']) && empty($data['address'])) {
            $data['address'] = $data['location'];
        }
        $data['notes'] = collect([
            ! empty($data['sells']) ? 'Sells: '.implode(', ', $data['sells']) : null,
            ! empty($data['interest']) ? 'Interest: '.self::SHOP_INTEREST[$data['interest']] : null,
            $data['notes'] ?? null,
        ])->filter()->implode("\n") ?: null;
        unset($data['sells'], $data['interest']);

        $credential = null;
        $plainPassword = null;
        if ($createLogin) {
            $loginEmail = strtolower(trim((string) $data['login_email']));
            $plainPassword = (string) $data['login_password'];
            $this->shops->assertLoginEmailFree(new Shop(), $loginEmail);
            $data['email'] = $loginEmail;
            $credential = [
                'email' => $loginEmail,
                'password' => $plainPassword,
                'name' => $data['owner_name'] ?: $data['name'],
                'phone' => $data['phone'] ?? null,
            ];
        }
        unset($data['login_email'], $data['login_password']);

        unset($data['photo']);

        $shop = $this->shops->create($data + [
            'status' => Shop::STATUS_ACTIVE,
            'assigned_salesman_id' => $user->id,
            'territory_id' => $user->salesmanProfile?->territory_id,
            // Match admin form default so orders are not blocked by a zero credit limit.
            'credit_limit' => $data['credit_limit'] ?? 150000,
        ], $user, $credential);

        if ($file = $request->file('photo')) {
            $path = $file->store('shops/'.$shop->id, 'public');
            $shop->forceFill(['photo_path' => $path])->save();
        }

        try {
            $this->notifications->shopAddedFromField($shop, $user);
        } catch (\Throwable $e) {
            report($e);
        }

        if ($request->boolean('check_in') && ! $this->openVisit($user)) {
            $this->visits->checkIn($user, $shop, [
                'purpose' => 'New shop',
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
            ]);
        }

        if ($credential && $plainPassword) {
            return redirect()->route('field.shops.login', $shop)
                ->with('login_result', $this->shopLoginResult($shop->fresh(['users']), $credential['email'], $plainPassword))
                ->with('success', $shop->name.' added with owner login.');
        }

        $openVisit = $this->openVisit($user);
        if ($openVisit && (int) $openVisit->shop_id === (int) $shop->id) {
            return redirect()->route('field.visit.show', $openVisit)
                ->with('success', $shop->name.' added and approved. You can take orders now.');
        }

        return redirect()->route('field.shops')
            ->with('success', $shop->name.' added and approved. You can take orders now.');
    }

    public function shopLogin(Request $request, Shop $shop)
    {
        abort_unless($this->shops->salesmanCanManage($shop, $request->user()), 403);

        $shop->load('users');

        return view('field.shop-login', [
            'shop' => $shop,
            'hasLogin' => $shop->users->isNotEmpty(),
            'suggestedPassword' => Str::password(10, symbols: false),
            'result' => $request->session()->get('login_result'),
        ]);
    }

    public function storeShopLogin(Request $request, Shop $shop)
    {
        abort_unless($this->shops->salesmanCanManage($shop, $request->user()), 403);

        if ($shop->status === Shop::STATUS_ON_HOLD || $shop->status === Shop::STATUS_REJECTED) {
            throw ValidationException::withMessages([
                'shop' => $shop->name.' was deactivated by the office. Ask admin to reactivate it.',
            ]);
        }

        $data = $request->validate([
            'login_email' => ['required', 'email', 'max:180'],
            'login_password' => ['required', 'string', 'min:8', 'max:64'],
        ], [
            'login_password.min' => 'Use at least 8 characters so the account is safe.',
        ]);

        $email = strtolower(trim($data['login_email']));
        $password = $data['login_password'];

        try {
            $this->shops->assertLoginEmailFree($shop, $email);
        } catch (ValidationException $e) {
            throw $e;
        }

        $this->shops->attachShopUser($shop, [
            'email' => $email,
            'password' => $password,
            'name' => $shop->owner_name ?: $shop->name,
            'phone' => $shop->phone,
        ], $request->user());

        if (! $shop->email) {
            $shop->forceFill(['email' => $email])->save();
        }

        return redirect()->route('field.shops.login', $shop)
            ->with('login_result', $this->shopLoginResult($shop->fresh(['users']), $email, $password));
    }

    /** @return array{ok:bool,checks:array<string,bool>,email:string,password:string,chat_url:?string} */
    private function shopLoginResult(Shop $shop, string $email, string $password): array
    {
        $user = $shop->users->firstWhere('email', $email) ?: $shop->users->first();
        $checks = [
            'account' => (bool) ($user && $user->portal === User::PORTAL_SHOP && $user->is_active),
            'password' => (bool) ($user && Hash::check($password, $user->password)),
            'shop' => (bool) $user,
            'active' => $shop->status === Shop::STATUS_ACTIVE,
        ];

        $whatsapp = app(WhatsAppService::class)->notifyApproval($shop->phone, $shop->name, $email, $password);

        return [
            'ok' => ! in_array(false, $checks, true),
            'checks' => $checks,
            'email' => $email,
            'password' => $password,
            'chat_url' => $whatsapp['chat_url'] ?? null,
        ];
    }

    public function approveShop(Request $request, Shop $shop)
    {
        abort_unless($this->shops->salesmanCanManage($shop, $request->user()), 403);

        if ($shop->status === Shop::STATUS_ON_HOLD || $shop->status === Shop::STATUS_REJECTED) {
            throw ValidationException::withMessages([
                'shop' => $shop->name.' was deactivated by the office. Ask admin to reactivate it.',
            ]);
        }

        $this->shops->approve($shop, $request->user());

        return back()->with('success', $shop->fresh()->name.' approved. You can take orders now.');
    }

    public function partners(Request $request)
    {
        $applications = PartnerInquiry::query()
            ->with('shop')
            ->where('submitted_by', $request->user()->id)
            ->latest()
            ->paginate(15);

        $byStatus = PartnerInquiry::query()->where('submitted_by', $request->user()->id)
            ->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');

        return view('field.partners', [
            'applications' => $applications,
            'loginsToCreate' => $this->loginsToCreate($request->user()),
            'counts' => [
                'all' => (int) $byStatus->sum(),
                'pending' => (int) (($byStatus[PartnerInquiry::STATUS_NEW] ?? 0) + ($byStatus[PartnerInquiry::STATUS_CONTACTED] ?? 0)),
                'accepted' => (int) ($byStatus[PartnerInquiry::STATUS_CONVERTED] ?? 0),
                'rejected' => (int) ($byStatus[PartnerInquiry::STATUS_CLOSED] ?? 0),
            ],
        ]);
    }

    public function partnerLogin(Request $request, PartnerInquiry $inquiry)
    {
        abort_unless($inquiry->submitted_by === $request->user()->id, 403);

        if (! $inquiry->isAccepted() || ! $inquiry->shop) {
            return redirect()->route('field.partners')->with('error', 'The office has not accepted '.$inquiry->business_name.' yet.');
        }

        if (! $request->session()->has('login_result') && ! $this->canSetPartnerLogin($request, $inquiry)) {
            return redirect()->route('field.partners')->with('error', 'The login for '.$inquiry->business_name.' is already set. Ask the office to reset it.');
        }

        return view('field.partner-login', [
            'inquiry' => $inquiry->load('shop'),
            'result' => $request->session()->get('login_result'),
        ]);
    }

    public function storePartnerLogin(Request $request, PartnerInquiry $inquiry)
    {
        abort_unless($inquiry->submitted_by === $request->user()->id, 403);
        abort_unless($this->canSetPartnerLogin($request, $inquiry), 403, 'The login is already set. Ask the office to reset it.');

        $data = $request->validate([
            'email' => ['required', 'email', 'max:180'],
            'password' => ['required', 'string', 'min:8', 'max:64'],
        ], [
            'password.min' => 'Use at least 8 characters so the account is safe.',
        ]);

        try {
            $login = $this->partnerInquiries->setLogin($inquiry, $data['email'], $data['password'], $request->user());
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['email' => $e->getMessage()]);
        }

        $request->session()->put("partner_login_set.{$inquiry->id}", now()->timestamp);

        return redirect()->route('field.partners.login', $inquiry)->with('login_result', [
            'ok' => $login['ok'],
            'checks' => $login['checks'],
            'email' => $login['user']->email,
            'password' => $data['password'],
            'chat_url' => $login['whatsapp']['chat_url'] ?? null,
        ]);
    }

    public function createPartner(Request $request)
    {
        $shops = $this->partnerCandidateShops($request->user());

        return view('field.partner-create', [
            'shops' => $shops,
            'selectedShop' => $shops->firstWhere('id', (int) $request->query('shop')),
            'businessTypes' => PartnerInquiry::BUSINESS_TYPES,
        ]);
    }

    public function storePartner(Request $request)
    {
        $user = $request->user();
        $shopIds = $this->partnerCandidateShops($user)->modelKeys();

        $data = $request->validate([
            'shop_id' => ['nullable', 'integer', Rule::in($shopIds)],
            'business_name' => ['required', 'string', 'max:180'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:180'],
            'phone' => ['required', 'string', 'max:20', new BangladeshPhone(required: true)],
            'city' => ['nullable', 'string', 'max:80'],
            'division' => ['nullable', 'string', 'max:80'],
            'district' => ['nullable', 'string', 'max:80'],
            'thana' => ['nullable', 'string', 'max:80'],
            'post_office' => ['nullable', 'string', 'max:120'],
            'location' => ['nullable', 'string', 'max:160'],
            'business_type' => ['nullable', Rule::in(array_keys(PartnerInquiry::BUSINESS_TYPES))],
            'message' => ['nullable', 'string', 'max:2000'],
        ], [
            'shop_id.in' => 'Pick one of your own shops, or leave it empty for a new business.',
        ]);
        $data['phone'] = BangladeshPhone::normalize($data['phone']);
        $data['email'] = strtolower(trim($data['email']));
        if (! empty($data['thana'])) {
            $data['city'] = $data['thana'];
        } elseif (! empty($data['district'])) {
            $data['city'] = $data['district'];
        }
        unset($data['division'], $data['district'], $data['thana'], $data['post_office'], $data['location']);

        if (User::query()->where('email', $data['email'])->where('portal', '!=', User::PORTAL_SHOP)->exists()) {
            throw ValidationException::withMessages(['email' => 'This email belongs to a staff or salesman account. Use the shop owner\'s own email.']);
        }

        $duplicate = PartnerInquiry::query()
            ->whereIn('status', [PartnerInquiry::STATUS_NEW, PartnerInquiry::STATUS_CONTACTED])
            ->where(function ($q) use ($data) {
                $q->where('email', $data['email'])->orWhere('phone', $data['phone']);
                if (! empty($data['shop_id'])) {
                    $q->orWhere('shop_id', $data['shop_id']);
                }
            })
            ->first();
        if ($duplicate) {
            throw ValidationException::withMessages([
                'email' => "{$duplicate->business_name} already has an application waiting (sent {$duplicate->created_at->format('d M')}).",
            ]);
        }

        $inquiry = $this->partnerInquiries->submitFromField($data, $user);

        try {
            $this->notifications->partnerFromField($inquiry, $user);
        } catch (\Throwable $e) {
            report($e);
        }

        return redirect()->route('field.partners')
            ->with('success', 'Partner application for '.$inquiry->business_name.' sent. The office will review it and send the owner their login.');
    }

    public function checkIn(Request $request, Shop $shop)
    {
        $this->assertAssignedShop($request, $shop);

        if (! in_array($shop->status, [Shop::STATUS_ACTIVE, Shop::STATUS_PENDING], true)) {
            throw ValidationException::withMessages(['shop' => $shop->name.' is '.strtolower($shop->statusLabel()).' and cannot be visited.']);
        }

        $data = $request->validate([
            'purpose' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        if ($redirect = $this->continueOrLeaveOpenVisit($request, $shop, 'Continue visit at '.$shop->name.'.')) {
            return $redirect;
        }

        $visit = $this->visits->checkIn($request->user(), $shop, $data);

        return redirect()->route('field.visit.show', $visit)->with('success', 'Checked in at '.$shop->name);
    }

    /** Pick an existing shop, then place an order on its behalf. */
    public function createOrder(Request $request)
    {
        $user = $request->user();

        $shops = $this->catalogShops($user, activeOnly: true);

        return view('field.order-create', [
            'shops' => $shops,
            'mineCount' => $shops->where('is_mine', true)->count(),
            'areaCounts' => $shops->groupBy('area_label')->map->count()->sortDesc(),
            'openVisit' => $this->openVisit($user),
        ]);
    }

    /** Select any active admin shop and jump into the order screen on its behalf. */
    public function placeOrder(Request $request, Shop $shop)
    {
        $this->assertCanOrderForShop($request, $shop);

        if ($shop->status === Shop::STATUS_PENDING && $this->shops->salesmanCanManage($shop, $request->user())) {
            $this->shops->approve($shop, $request->user());
            $shop->refresh();
        }

        if ($shop->status !== Shop::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'shop' => $shop->name.' cannot take orders ('.strtolower($shop->statusLabel()).'). Ask the office if it was deactivated.',
            ]);
        }

        if ($redirect = $this->continueOrLeaveOpenVisit($request, $shop, 'Continue ordering for '.$shop->name.'.')) {
            return $redirect;
        }

        $data = $request->validate([
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $visit = $this->visits->checkIn($request->user(), $shop->fresh(), [
            'purpose' => 'Order collection',
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
        ], onBehalf: true);

        return redirect()->route('field.visit.show', $visit)
            ->with('success', 'Ordering on behalf of '.$shop->name.'. Add products and send.');
    }

    public function showVisit(Request $request, ShopVisit $visit)
    {
        abort_unless($visit->salesman_id === $request->user()->id, 403);

        $visit->load(['shop.priceGroup', 'order']);
        $group = $visit->shop?->priceGroup;

        $catalog = Product::query()
            ->with(['prices', 'category', 'offers', 'images'])
            ->where('status', Product::STATUS_ACTIVE)
            ->where('is_published', true)
            ->orderBy('name')
            ->get()
            ->map(function (Product $p) use ($group) {
                $offer = $p->activeOffer();

                return [
                    'id' => $p->id,
                    'name' => $p->name,
                    'sku' => $p->sku,
                    'category' => $p->category?->name ?? 'Other',
                    'price' => $p->priceForGroup($group),
                    'min_price' => (float) ($p->minimum_selling_price ?? 0),
                    'list_price' => (float) $p->wholesale_price,
                    'offer_title' => $offer?->title,
                    'on_offer' => (bool) $offer,
                    'stock' => $p->availableStock(),
                    'image' => $p->imageUrl(),
                    'images' => $p->imageUrls(),
                ];
            })
            ->values();

        return view('field.visit', [
            'visit' => $visit,
            'canOrder' => $visit->shop?->status === Shop::STATUS_ACTIVE,
            'catalog' => $catalog,
            'categories' => $catalog->pluck('category')->unique()->sort()->values(),
        ]);
    }

    public function checkOut(Request $request, ShopVisit $visit)
    {
        abort_unless($visit->salesman_id === $request->user()->id, 403);

        $visit = $this->closeVisitFromRequest($request, $visit);

        return redirect()->route('field.dashboard')
            ->with('success', 'Visit at '.$visit->shop?->name.' saved · '.$visit->outcomeLabel().'.');
    }

    /**
     * Leave a shop visit (Back / nav away). Always stores the visit — with order or without.
     */
    public function leaveVisit(Request $request, ShopVisit $visit)
    {
        abort_unless($visit->salesman_id === $request->user()->id, 403);

        $to = match ($request->input('redirect')) {
            'dashboard', 'home' => route('field.dashboard'),
            'orders' => route('field.orders'),
            default => route('field.shops'),
        };

        $beacon = $request->header('X-Beacon') === '1' || $request->boolean('beacon');

        // Phone back / tab close must not invent an empty visit. The resume banner
        // keeps the check-in open until I'm leaving or an order is sent.
        if ($beacon) {
            return response()->noContent();
        }

        if ($visit->isOpen()) {
            $visit = $this->closeVisitFromRequest($request, $visit);
        }

        $label = $visit->fresh()?->outcomeLabel() ?? 'Saved';

        return redirect($to)
            ->with('success', 'Visit at '.$visit->shop?->name.' saved · '.$label.'.');
    }

    public function submitOrder(Request $request, ShopVisit $visit)
    {
        abort_unless($visit->salesman_id === $request->user()->id, 403);

        if (! $visit->isOpen()) {
            throw ValidationException::withMessages(['visit' => 'This visit is already closed. Check in again to take a new order.']);
        }

        $data = $request->validate([
            'notes' => ['required', 'string', 'max:1000'],
            'paid_now' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'payment_method' => ['nullable', Rule::in([
                \App\Models\Payment::METHOD_CASH,
                \App\Models\Payment::METHOD_MOBILE,
                \App\Models\Payment::METHOD_BANK,
            ])],
            'payment_reference' => ['nullable', 'string', 'max:120'],
            'discount_total' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'items' => ['required', 'array'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0.01', 'max:99999999'],
        ], [
            'notes.required' => 'Write what you talked about with the owner.',
        ]);

        $discount = round((float) ($data['discount_total'] ?? 0), 2);

        $order = DB::transaction(function () use ($request, $visit, $data, $discount) {
            $order = $this->orders->collectFromSalesman(
                $request->user(),
                $visit->shop,
                $data['items'],
                $visit,
                $data['notes'] ?? null,
                $discount,
            );

            $this->visits->checkOut($visit->fresh(), [
                'outcome' => ShopVisit::OUTCOME_ORDER_TAKEN,
                'notes' => $data['notes'],
            ], $request->user());

            return $order;
        });

        $msg = 'Order '.$order->number.' sent for approval. Payment is collected when you deliver.';

        return redirect()
            ->route('field.orders.show', $order)
            ->with('success', $msg);
    }

    public function orders(Request $request)
    {
        $filters = [
            'waiting' => [Order::STATUS_PENDING_AUDIT, Order::STATUS_AWAITING_ADVANCE],
            'active' => [Order::STATUS_APPROVED, Order::STATUS_PICKING, Order::STATUS_PICKED, Order::STATUS_PACKED, Order::STATUS_DISPATCHED],
            'delivered' => [Order::STATUS_DELIVERED],
            'rejected' => [Order::STATUS_REJECTED, Order::STATUS_CANCELLED],
        ];
        $filter = array_key_exists($request->query('status'), $filters) ? $request->query('status') : null;

        $orders = Order::query()
            ->with(['shop', 'invoice', 'advanceInvoice', 'delivery'])
            ->where('salesman_id', $request->user()->id)
            ->when($filter, fn ($q) => $q->whereIn('status', $filters[$filter]))
            ->latest('submitted_at')
            ->paginate(15)
            ->withQueryString();

        $byStatus = Order::query()->where('salesman_id', $request->user()->id)
            ->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');
        $counts = collect($filters)->map(fn (array $statuses) => (int) collect($statuses)->sum(fn ($s) => $byStatus[$s] ?? 0))
            ->put('all', (int) $byStatus->sum());

        return view('field.orders', compact('orders', 'filter', 'counts'));
    }

    public function showOrder(Request $request, Order $order)
    {
        abort_unless($this->salesmanCanAccessOrder($request->user(), $order), 403);

        $order->load(['shop', 'items', 'visit', 'advanceInvoice', 'invoice', 'delivery']);

        if ($order->isDelivered() && ! $order->invoice_id) {
            app(InvoiceService::class)->createFromOrder($order->fresh('items'), $request->user());
            $order->refresh()->load(['shop', 'items', 'visit', 'advanceInvoice', 'invoice', 'delivery']);
        }

        return view('field.order-show', [
            'order' => $order,
            'pendingBill' => $order->pendingBillPayment(),
            'collectibleBalance' => $order->collectibleBalance(),
        ]);
    }

    public function discountOrder(Request $request, Order $order)
    {
        abort_unless($this->salesmanCanAccessOrder($request->user(), $order), 403);

        $data = $request->validate([
            'discount_total' => ['required', 'numeric', 'min:0'],
        ]);

        $posted = round((float) $data['discount_total'], 2);
        $amount = $request->boolean('add')
            ? round((float) $order->discount_total + $posted, 2)
            : $posted;

        $this->orders->applyDiscount($order, $amount, $request->user());

        return back()->with('success', 'Order total reduced. Discount ৳ '.number_format($amount, 2).'.');
    }

    public function collectBill(Request $request, Order $order)
    {
        abort_unless($this->salesmanCanAccessOrder($request->user(), $order), 403);

        if (! $order->canCollectNow()) {
            throw ValidationException::withMessages([
                'amount' => 'Payment starts when this order is delivered.',
            ]);
        }

        $data = $request->validate([
            'amount' => ['nullable', 'numeric', 'min:0'],
            'method' => ['nullable', 'in:'.implode(',', Payment::METHODS)],
            'reference' => ['nullable', 'string', 'max:120'],
        ]);

        $amount = round((float) ($data['amount'] ?? 0), 2);
        $order->load(['invoice', 'advanceInvoice', 'items']);

        if ($amount > 0.009) {
            $this->orders->collectAnytime(
                $order,
                $request->user(),
                $amount,
                $data['method'] ?? Payment::METHOD_CASH,
                $data['reference'] ?? null,
            );
        }

        $baki = $order->fresh(['invoice', 'advanceInvoice'])->collectibleBalance();
        if ($amount > 0.009) {
            $msg = 'Collected ৳ '.number_format($amount, 2).'.';
            $msg .= $baki > 0.009
                ? ' Baki ৳ '.number_format($baki, 2).' left — collect whenever they have cash.'
                : ' Bill cleared. Office will confirm.';
        } else {
            $msg = 'Left as baki ৳ '.number_format($baki, 2).'. Collect later when the owner has cash.';
        }

        return redirect()->route('field.orders.show', $order)->with('success', $msg);
    }

    public function deliverOrder(Request $request, Order $order)
    {
        abort_unless($this->salesmanCanAccessOrder($request->user(), $order), 403);

        $this->orders->deliver($order, $request->user(), 'Salesman marked the goods delivered');

        return redirect()->route('field.orders.show', $order)
            ->with('success', 'Order '.$order->number.' delivered. Collect remaining baki whenever they have cash.');
    }

    public function collections(Request $request)
    {
        $user = $request->user();
        $people = $this->collections->byShop($user, $request->search);
        $receipts = $this->collections->receipts($user);

        return view('field.collections', [
            'people' => $people,
            'receipts' => $receipts,
            'receivedTotal' => round((float) $people->sum('received'), 2),
            'pendingTotal' => round((float) $people->sum('pending'), 2),
            'youCollected' => round((float) $people->sum('you_collected'), 2),
            'bakiTotal' => round((float) $people->sum('baki'), 2),
        ]);
    }

    private function salesmanCanAccessOrder(User $user, Order $order): bool
    {
        if ((int) $order->salesman_id === (int) $user->id) {
            return true;
        }

        return $user->assignedShops()->where('shops.id', $order->shop_id)->exists();
    }

    public function earnings(Request $request)
    {
        $user = $request->user();
        $base = Commission::query()->where('salesman_id', $user->id)->where('status', '!=', Commission::STATUS_REJECTED);
        $month = (clone $base)->where('year', now()->year)->where('month', now()->month);
        $salary = $this->salaries->summaryFor($user);

        return view('field.earnings', [
            'rule' => CommissionRule::activeDefault(),
            'monthEarned' => (float) (clone $month)->sum('commission_amount'),
            'monthPending' => (float) (clone $month)->whereIn('status', [Commission::STATUS_ACCRUED, Commission::STATUS_APPROVED])->sum('commission_amount'),
            'totalPaid' => (float) (clone $base)->where('status', Commission::STATUS_PAID)->sum('commission_amount'),
            'awaitingPayout' => (float) (clone $base)->whereIn('status', [Commission::STATUS_ACCRUED, Commission::STATUS_APPROVED])->sum('commission_amount'),
            'commissions' => (clone $base)->with(['payment.shop', 'order.shop'])->latest()->limit(30)->get(),
            'rewards' => Reward::query()->where('salesman_id', $user->id)
                ->where('status', '!=', Reward::STATUS_CANCELLED)->latest()->limit(10)->get(),
            'salary' => $salary,
        ]);
    }

    public function deliveries(Request $request)
    {
        $user = $request->user();
        $shopIds = $user->assignedShops()->pluck('shops.id');

        $base = Delivery::query()
            ->where(function ($q) use ($user, $shopIds) {
                $q->where('assigned_to', $user->id)
                    ->orWhereHas('order', fn ($o) => $o->where('salesman_id', $user->id))
                    ->orWhereIn('shop_id', $shopIds);
            });

        $byStatus = (clone $base)
            ->selectRaw('status, COUNT(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status');

        $counts = [
            'all' => (int) $byStatus->sum(),
            'out' => (int) ($byStatus[Delivery::STATUS_OUT_FOR_DELIVERY] ?? 0),
            'delivered' => (int) ($byStatus[Delivery::STATUS_DELIVERED] ?? 0),
            'failed' => (int) ($byStatus[Delivery::STATUS_FAILED] ?? 0),
        ];

        $filters = [
            'out' => Delivery::STATUS_OUT_FOR_DELIVERY,
            'delivered' => Delivery::STATUS_DELIVERED,
            'failed' => Delivery::STATUS_FAILED,
        ];
        $filter = array_key_exists($request->query('status'), $filters) ? $request->query('status') : null;

        $deliveries = (clone $base)
            ->with(['shop', 'order.invoice', 'order.advanceInvoice', 'order.delivery', 'assignee'])
            ->when($filter, fn ($q) => $q->where('status', $filters[$filter]))
            ->orderByRaw("CASE status WHEN 'out_for_delivery' THEN 0 WHEN 'failed' THEN 1 ELSE 2 END")
            ->latest('dispatched_at')
            ->paginate(20)
            ->withQueryString();

        return view('field.deliveries', compact('deliveries', 'filter', 'counts'));
    }

    public function showDelivery(Request $request, Delivery $delivery)
    {
        $this->assertSalesmanDelivery($request->user(), $delivery);
        $this->deliveryStock->seedItems($delivery);
        $delivery->load(['items.product', 'shop', 'order.items', 'assignee', 'warehouse']);

        return view('field.delivery-show', [
            'delivery' => $delivery,
            'balances' => $this->deliveryStock->salesmanBalances($request->user()),
        ]);
    }

    public function receiveDelivery(Request $request, Delivery $delivery)
    {
        $this->assertSalesmanDelivery($request->user(), $delivery);

        $data = $request->validate([
            'items' => ['required', 'array'],
            'items.*.id' => ['required', 'integer'],
            'items.*.qty' => ['required', 'integer', 'min:0'],
        ]);

        $this->deliveryStock->receive($delivery, $data['items'], $request->user());

        return redirect()->route('field.deliveries.show', $delivery)
            ->with('success', 'Received quantities saved. Stock is now with you for delivery.');
    }

    public function updateDeliveryQuantities(Request $request, Delivery $delivery)
    {
        $this->assertSalesmanDelivery($request->user(), $delivery);

        $data = $request->validate([
            'items' => ['required', 'array'],
            'items.*.id' => ['required', 'integer'],
            'items.*.qty' => ['required', 'integer', 'min:0'],
            'complete' => ['nullable', 'boolean'],
        ]);

        $this->deliveryStock->recordDeliveredQuantities(
            $delivery,
            $data['items'],
            $request->user(),
            $request->boolean('complete', true),
        );

        if ($request->boolean('complete', true) && $delivery->fresh()->status !== Delivery::STATUS_DELIVERED) {
            $this->fulfilment->markDelivered($delivery->fresh(), $request->user(), 'Salesman confirmed delivered quantities');
        }

        return redirect()->route('field.deliveries.show', $delivery)
            ->with('success', 'Delivered quantities saved. Remaining stock stays with you until you return it.');
    }

    public function returnDeliveryStock(Request $request, Delivery $delivery)
    {
        $this->assertSalesmanDelivery($request->user(), $delivery);

        $data = $request->validate([
            'items' => ['required', 'array'],
            'items.*.id' => ['required', 'integer'],
            'items.*.qty' => ['required', 'integer', 'min:0'],
        ]);

        $this->deliveryStock->returnToWarehouse($delivery, $data['items'], $request->user());

        return redirect()->route('field.deliveries.show', $delivery)
            ->with('success', 'Returned stock booked back into the warehouse.');
    }

    public function deliveryStock(Request $request)
    {
        $balances = $this->deliveryStock->salesmanBalances($request->user());

        return view('field.delivery-stock', [
            'balances' => $balances,
            'remainingTotal' => (int) $balances->sum('remaining'),
            'deliveredTotal' => (int) $balances->sum('delivered'),
            'receivedTotal' => (int) $balances->sum('received'),
            'returnedTotal' => (int) $balances->sum('returned'),
        ]);
    }

    private function assertSalesmanDelivery(User $user, Delivery $delivery): void
    {
        $allowed = (int) $delivery->assigned_to === (int) $user->id
            || (int) $delivery->order?->salesman_id === (int) $user->id
            || $user->assignedShops()->where('shops.id', $delivery->shop_id)->exists();

        abort_unless($allowed, 403);
    }

    public function announcements(Request $request)
    {
        $user = $request->user();

        $announcements = Announcement::query()
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('published_at')->orWhere('published_at', '<=', now());
            })
            ->where(function ($q) use ($user) {
                $q->where('send_to_all', true)
                    ->orWhereHas('salesmen', fn ($s) => $s->where('users.id', $user->id));
            })
            ->latest('published_at')
            ->paginate(20);

        return view('field.announcements', compact('announcements'));
    }

    public function notifications(Request $request)
    {
        $notifications = $request->user()->notifications()->latest()->paginate(30);

        return view('field.notifications', compact('notifications'));
    }

    public function markNotificationRead(Request $request, string $id)
    {
        $notification = $request->user()->notifications()->where('id', $id)->firstOrFail();
        $notification->markAsRead();

        return back();
    }

    private function openVisit(User $user): ?ShopVisit
    {
        return ShopVisit::query()->where('salesman_id', $user->id)->whereNull('checked_out_at')->first();
    }

    /**
     * Daily visit counts for a salesman since $from (newest day first).
     *
     * @return Collection<string, array{date: Carbon, visits: int, shops: int, rows: Collection}>
     */
    private function visitDayLog(User $user, Carbon $from, bool $fillDays = false, ?Carbon $to = null): Collection
    {
        $until = ($to ?? now())->copy()->endOfDay();

        $visits = ShopVisit::query()
            ->with('shop')
            ->where('salesman_id', $user->id)
            ->where('checked_in_at', '>=', $from->copy()->startOfDay())
            ->where('checked_in_at', '<=', $until)
            ->orderByDesc('checked_in_at')
            ->get();

        $grouped = $visits
            ->groupBy(fn (ShopVisit $visit) => $visit->checked_in_at->toDateString())
            ->map(fn ($rows, $date) => [
                'date' => Carbon::parse($date),
                'visits' => $rows->count(),
                'shops' => $rows->unique('shop_id')->count(),
                'rows' => $rows->values(),
            ]);

        if (! $fillDays) {
            return $grouped;
        }

        $days = collect();
        for ($day = $from->copy()->startOfDay(); $day->lte($until->copy()->startOfDay()); $day->addDay()) {
            $key = $day->toDateString();
            $days[$key] = $grouped[$key] ?? [
                'date' => $day->copy(),
                'visits' => 0,
                'shops' => 0,
                'rows' => collect(),
            ];
        }

        return $days;
    }

    /** Today's route: only shops admin assigned to this salesman. */
    private function shopsWithVisits(User $user): Collection
    {
        return $this->catalogShops($user, assignedOnly: true);
    }

    /**
     * Active (and pending) shops the salesman can see — assigned and not assigned —
     * with visit flags and an area label for grouping.
     *
     * @return Collection<int, Shop>
     */
    private function catalogShops(User $user, bool $assignedOnly = false, bool $activeOnly = false): Collection
    {
        $lastVisits = ShopVisit::query()
            ->where('salesman_id', $user->id)
            ->selectRaw('shop_id, MAX(checked_in_at) as last_at')
            ->groupBy('shop_id')
            ->pluck('last_at', 'shop_id');

        $shops = Shop::query()
            ->with(['territory', 'priceGroup', 'assignedSalesman'])
            ->when(
                $activeOnly,
                fn ($q) => $q->where('status', Shop::STATUS_ACTIVE),
                fn ($q) => $q->whereIn('status', [Shop::STATUS_ACTIVE, Shop::STATUS_PENDING])
            )
            ->when($assignedOnly, fn ($q) => $q->where('assigned_salesman_id', $user->id))
            ->orderBy('name')
            ->get();

        return $shops->each(function (Shop $shop) use ($lastVisits, $user) {
            $last = $lastVisits[$shop->id] ?? null;
            $shop->last_visit_at = $last ? Carbon::parse($last) : null;
            $shop->visited_today = $shop->last_visit_at?->isToday() ?? false;
            $shop->is_mine = (int) $shop->assigned_salesman_id === (int) $user->id;
            $shop->area_label = trim((string) ($shop->thana ?: $shop->city ?: $shop->district ?: $shop->territory?->name ?: '')) ?: 'No area';
        });
    }

    /**
     * A salesman sets the first login for a shop they still look after. Changing it later is an
     * office job, except for fixing a typo on the same device shortly after setting it.
     */
    private function canSetPartnerLogin(Request $request, PartnerInquiry $inquiry): bool
    {
        if (! $inquiry->shop || $inquiry->shop->assigned_salesman_id !== $request->user()->id) {
            return false;
        }

        if ($inquiry->needsLogin()) {
            return true;
        }

        $setAt = $request->session()->get("partner_login_set.{$inquiry->id}");

        return $setAt && now()->timestamp - $setAt < 30 * 60;
    }

    private function loginsToCreate(User $user): Collection
    {
        return PartnerInquiry::query()
            ->with('shop')
            ->where('submitted_by', $user->id)
            ->where('status', PartnerInquiry::STATUS_CONVERTED)
            ->whereNull('portal_email')
            ->latest('reviewed_at')
            ->get();
    }

    /** @return Collection<int, Shop> shops this salesman looks after that have no portal login yet */
    private function partnerCandidateShops(User $user): Collection
    {
        return Shop::query()
            ->where(fn ($q) => $q->where('assigned_salesman_id', $user->id)->orWhere('created_by', $user->id))
            ->where('status', '!=', Shop::STATUS_REJECTED)
            ->whereDoesntHave('users')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'owner_name', 'phone', 'email', 'city', 'status']);
    }

    private function assertAssignedShop(Request $request, Shop $shop): void
    {
        if ((int) $shop->assigned_salesman_id !== (int) $request->user()->id) {
            throw ValidationException::withMessages([
                'shop' => 'This shop is not assigned to you.',
            ]);
        }
    }

    /** Any active shop from admin can take a field order on its behalf. */
    private function assertCanOrderForShop(Request $request, Shop $shop): void
    {
        if ($shop->status !== Shop::STATUS_ACTIVE
            && ! ($shop->status === Shop::STATUS_PENDING && $this->shops->salesmanCanManage($shop, $request->user()))) {
            throw ValidationException::withMessages([
                'shop' => $shop->name.' is not active yet. Ask the office to approve it first.',
            ]);
        }
    }

    /**
     * One visit at a time. Same shop resumes; another shop must go through I'm leaving.
     */
    private function continueOrLeaveOpenVisit(Request $request, Shop $shop, string $continueMessage): ?\Illuminate\Http\RedirectResponse
    {
        $open = $this->openVisit($request->user());
        if (! $open) {
            return null;
        }

        if ((int) $open->shop_id === (int) $shop->id) {
            return redirect()->route('field.visit.show', $open)->with('success', $continueMessage);
        }

        return redirect()
            ->route('field.visit.show', ['visit' => $open, 'leave' => 1])
            ->with('error', 'Leave '.$open->shop?->name.' first. Write what you talked about, then start at '.$shop->name.'.');
    }

    private function closeVisitFromRequest(Request $request, ShopVisit $visit): ShopVisit
    {
        $visit->loadMissing('shop');
        $needsPhoto = $visit->needsPhotoEvidence();

        $data = $request->validate([
            'outcome' => ['nullable', 'in:order_taken,no_order,closed,follow_up'],
            'notes' => ['required', 'string', 'max:1000'],
            'photo' => [$needsPhoto ? 'required' : 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ], [
            'notes.required' => 'Write what you talked about with the owner.',
            'photo.required' => 'Take a photo at the shop as visit evidence.',
            'photo.image' => 'Visit evidence must be a photo.',
        ]);

        $photoPath = $visit->photo_path;
        if ($file = $request->file('photo')) {
            $photoPath = $file->store('visits/'.$visit->id, 'public');
        }

        $outcome = $visit->order_id
            ? ShopVisit::OUTCOME_ORDER_TAKEN
            : (string) ($data['outcome'] ?? ShopVisit::OUTCOME_NO_ORDER);

        if (! in_array($outcome, [
            ShopVisit::OUTCOME_ORDER_TAKEN,
            ShopVisit::OUTCOME_NO_ORDER,
            ShopVisit::OUTCOME_CLOSED,
            ShopVisit::OUTCOME_FOLLOW_UP,
        ], true)) {
            $outcome = ShopVisit::OUTCOME_NO_ORDER;
        }

        return $this->visits->checkOut($visit, [
            'outcome' => $outcome,
            'notes' => $data['notes'] ?? $visit->notes,
            'photo_path' => $photoPath,
        ], $request->user());
    }

    /** Every active shop — salesman picks one and orders on its behalf. */
    private function orderableShops(User $user): Collection
    {
        return $this->catalogShops($user, activeOnly: true);
    }

    /**
     * Area names already used on shops, plus territory names, for the add-shop dropdown.
     *
     * @return list<string>
     */
    private function knownAreas(): array
    {
        $cities = Shop::query()
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->distinct()
            ->pluck('city');

        $territories = Territory::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name');

        return $cities->merge($territories)
            ->map(fn ($name) => trim((string) $name))
            ->filter()
            ->unique(fn ($name) => mb_strtolower($name))
            ->sortBy(fn ($name) => mb_strtolower($name), SORT_NATURAL)
            ->values()
            ->all();
    }
}
