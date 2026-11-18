@extends('layouts.app')
@section('title', $shop->name)
@section('content')
    <x-page-header title="{{ $shop->name }}" subtitle="{{ $shop->code }}">
        <x-slot:tools>
            <x-badge :status="$shop->statusLabel()" />
            <a class="btn btn-ghost" href="{{ route('shops.statement', $shop) }}">Account statement</a>
            @can('update', $shop)
                <a class="btn btn-ghost" href="{{ route('shops.edit', $shop) }}">Edit</a>
            @endcan
            @can('shops.approve')
                @if ($shop->status !== \App\Models\Shop::STATUS_ACTIVE)
                    <form action="{{ route('shops.approve', $shop) }}" method="post">@csrf
                        <button class="btn btn-primary" type="submit">Activate shop</button>
                    </form>
                @else
                    <form action="{{ route('shops.deactivate', $shop) }}" method="post"
                        onsubmit="return confirm('Deactivate this shop? Salesmen will not be able to take new orders.')">@csrf
                        <button class="btn btn-ghost" type="submit">Deactivate</button>
                    </form>
                @endif
            @endcan
        </x-slot:tools>
    </x-page-header>

    @if (session('success'))
        <div class="card" style="padding:12px 16px;margin-bottom:14px;background:#e8f8ee;color:#15803d">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div class="card" style="padding:12px 16px;margin-bottom:14px;background:#fef2f2;color:#b91c1c">{{ $errors->first() }}</div>
    @endif
    @if (session('whatsapp_chat_url'))
        <div class="card" style="padding:12px 16px;margin-bottom:14px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
            <div>
                <div style="font-weight:700">Notify owner on WhatsApp</div>
                <div class="muted" style="font-size:13px;margin-top:2px">Opens WhatsApp with approval + login details ready to send.</div>
            </div>
            <a class="btn btn-primary" href="{{ session('whatsapp_chat_url') }}" target="_blank" rel="noopener">Open WhatsApp</a>
        </div>
    @endif

    @if ($shop->creator?->portal === \App\Models\User::PORTAL_SALESMAN)
        <div class="card" style="padding:12px 16px;margin-bottom:14px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;border-left:4px solid {{ $shop->status === \App\Models\Shop::STATUS_ACTIVE ? '#16A34A' : '#D97706' }}">
            <div>
                <div style="font-weight:700">
                    @if ($shop->status === \App\Models\Shop::STATUS_ACTIVE)
                        Approved by salesman {{ $shop->creator->name }}
                    @else
                        Added in the field by {{ $shop->creator->name }}
                    @endif
                </div>
                <div class="muted" style="font-size:13px;margin-top:2px">
                    {{ $shop->created_at?->format('d M Y, g:i A') }}
                    @if ($shop->approved_at)
                        · Activated {{ $shop->approved_at->format('d M Y, g:i A') }}
                    @endif
                    @if ($shop->status === \App\Models\Shop::STATUS_PENDING)
                        · Still pending — salesman can approve in the field app, or you can activate here.
                    @elseif ($shop->status === \App\Models\Shop::STATUS_ACTIVE)
                        · Active for orders. Office can edit details or deactivate if needed — no re-approval required.
                    @endif
                    @if ($shop->notes) · “{{ $shop->notes }}” @endif
                </div>
            </div>
            @if ($shop->latitude !== null)
                <a class="btn btn-ghost" href="{{ $shop->mapUrl() }}" target="_blank" rel="noopener">View GPS location</a>
            @endif
        </div>
    @endif

    <div class="grid-4" style="margin-bottom:16px">
        @foreach ([
            ['Credit limit (report only)', \App\Support\DemoData::taka($shop->credit_limit)],
            ['Baki', \App\Support\DemoData::taka($shop->outstanding_balance)],
            ['Grade', $shop->grade ?: '—'],
            ['Rating', $shop->rating !== null ? $shop->rating.'/5' : '—'],
            ['Available credit', \App\Support\DemoData::taka($shop->availableCredit())],
            ['Payment terms', $shop->payment_terms_days.' days'],
        ] as $card)
            <div class="card" style="padding:16px">
                <div class="muted">{{ $card[0] }}</div>
                <div style="font-size:20px;font-weight:800;margin-top:6px">{{ $card[1] }}</div>
            </div>
        @endforeach
    </div>

    <div class="grid-2" style="margin-bottom:16px">
        <div class="card" style="padding:18px">
            <div class="section-title" style="margin-bottom:10px">Partner details</div>
            <p class="muted" style="margin:0 0 6px">Owner: <strong style="color:var(--text)">{{ $shop->owner_name }}</strong></p>
            <p class="muted" style="margin:0 0 6px">City / territory: {{ $shop->city ?: '—' }} / {{ $shop->territory?->name ?: '—' }}</p>
            <p class="muted" style="margin:0 0 6px">Price group: {{ $shop->priceGroup?->name ?: '—' }}</p>
            <p class="muted" style="margin:0 0 6px">Salesman: {{ $shop->assignedSalesman?->name ?: 'Unassigned' }}</p>
            <p class="muted" style="margin:0">{{ $shop->address }}</p>
        </div>
        <div class="card" style="padding:18px">
            <div class="section-title" style="margin-bottom:10px">Portal users</div>
            @forelse ($shop->users as $user)
                <div style="padding:8px 0;border-bottom:1px solid #f1f3f8;font-size:13px">
                    <strong>{{ $user->name }}</strong> · {{ $user->email }}
                    @if ($user->pivot->is_primary) <span class="badge badge-active">Primary</span> @endif
                </div>
            @empty
                <p class="muted">No portal users yet.</p>
            @endforelse

            @can('manageCredentials', $shop)
                <form action="{{ route('shops.credentials', $shop) }}" method="post" class="form-grid" style="margin-top:14px">
                    @csrf
                    <div>
                        <label class="label">Login email</label>
                        <input class="input" style="width:100%" type="email" name="login_email" value="{{ $shop->email }}" required>
                    </div>
                    <div>
                        <label class="label">Password</label>
                        <input class="input" style="width:100%" type="text" name="login_password" minlength="8" value="{{ old('login_password', \Illuminate\Support\Str::password(10, symbols: false)) }}" required>
                    </div>
                    <div class="form-span" style="display:flex;flex-direction:column;gap:10px">
                        <label style="display:flex;align-items:center;gap:8px;font-size:13px">
                            <input type="checkbox" name="notify_whatsapp" value="1" checked>
                            Notify owner on WhatsApp after issuing credentials
                        </label>
                        <button class="btn btn-primary" type="submit">Issue / reset credentials</button>
                    </div>
                </form>
            @endcan
        </div>
    </div>

    <div class="card">
        <div class="fa-card-head">
            <strong>Visits <span class="muted" style="font-weight:500">· {{ $shop->visits_count }} total</span></strong>
            @if ($shop->visits_count > 0)
                <a class="link" href="{{ route('visits.index', ['period' => 'all', 'shop_id' => $shop->id]) }}#log">See all visits ›</a>
            @endif
        </div>
        <div class="table-wrap">
            <table class="data">
                <tbody>
                    @forelse ($recentVisits as $visit)
                        <tr>
                            <td style="white-space:nowrap">{{ $visit->checked_in_at?->format('d M Y H:i') }}</td>
                            <td>{{ $visit->salesman?->name }}</td>
                            <td><x-badge :status="$visit->outcomeLabel()" /></td>
                            <td>
                                @if ($visit->order)
                                    <a class="link" href="{{ route('orders.show', $visit->order) }}">{{ $visit->order->number }}</a>
                                @else
                                    <span class="muted">{{ $visit->notes ? \Illuminate\Support\Str::limit($visit->notes, 60) : '—' }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td class="muted" style="padding:16px">No visits recorded yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
