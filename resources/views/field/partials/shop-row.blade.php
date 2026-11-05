@php
    $outstanding = (float) $shop->outstanding_balance;
    $phone = preg_replace('/[^0-9+]/', '', (string) $shop->phone);
    $mapQuery = urlencode(trim(implode(', ', array_filter([$shop->name, $shop->address, $shop->city]))));
    $isOpenHere = $openVisit && $openVisit->shop_id === $shop->id;
    $tone = ['violet', 'blue', 'green', 'amber', 'rose', 'sky', 'teal'][crc32((string) $shop->name) % 7];
    $pending = $shop->status === \App\Models\Shop::STATUS_PENDING;
@endphp
<tr @if ($filterable ?? false) x-show="ok($el)" @endif
    data-search="{{ strtolower($shop->name.' '.$shop->code.' '.$shop->owner_name.' '.$shop->city.' '.$shop->territory?->name.' '.($shop->area_label ?? '')) }}"
    data-state="{{ $shop->visited_today ? 'done' : 'todo' }}"
    data-owner="{{ ! empty($shop->is_mine) ? 'mine' : 'other' }}"
    data-area="{{ $shop->area_label ?? ($shop->city ?: $shop->territory?->name ?: 'No area') }}">
    <td class="shop">
        <div class="who">
            <span class="f-av tone-{{ $tone }} {{ $shop->visited_today ? 'done' : '' }}">
                @if ($shop->visited_today)<i data-lucide="check"></i>@else{{ strtoupper(mb_substr($shop->name, 0, 1)) }}@endif
            </span>
            <span style="min-width:0">
                <span class="strong">{{ $shop->name }}</span>
                <span class="sub">{{ $shop->owner_name ?: $shop->code }}</span>
            </span>
        </div>
    </td>
    @unless ($hideArea ?? false)
        <td class="area">{{ $shop->city ?: $shop->territory?->name ?: '—' }}</td>
    @endunless
    @unless ($hideVisit ?? false)
        <td class="visit">
            @if ($isOpenHere)
                <span class="f-status amber">I'm here</span>
            @elseif ($shop->visited_today)
                <span class="f-status green">Visited today</span>
            @elseif ($shop->last_visit_at)
                <span class="f-muted">{{ $shop->last_visit_at->diffForHumans(null, true) }} ago</span>
            @else
                <span class="f-status gray">Never</span>
            @endif
        </td>
    @endunless
    <td class="num" style="{{ $outstanding > 0 ? 'color:var(--f-red);font-weight:700' : '' }}">{{ \App\Support\DemoData::taka($outstanding) }}</td>
    @if ($showGroup ?? false)
        <td class="owner">
            @if (! empty($shop->is_mine))
                <span class="f-chip green">My shop</span>
            @elseif ($shop->assignedSalesman)
                <span class="f-chip">{{ $shop->assignedSalesman->name }}</span>
            @else
                <span class="f-chip amber">Unassigned</span>
            @endif
        </td>
    @endif
    <td class="act">
        <div class="actions">
            @if ($phone)
                <a class="f-btn f-btn-ghost f-btn-sm f-btn-icon" href="tel:{{ $phone }}" title="Call {{ $shop->phone }}" aria-label="Call {{ $shop->name }}"><i data-lucide="phone"></i></a>
            @endif
            <a class="f-btn f-btn-ghost f-btn-sm f-btn-icon" href="https://www.google.com/maps/search/?api=1&query={{ $mapQuery }}" target="_blank" rel="noopener" title="Directions" aria-label="Directions to {{ $shop->name }}"><i data-lucide="map-pin"></i></a>
            @if ($pending)
                <form method="post" action="{{ route('field.shops.approve', $shop) }}">
                    @csrf
                    <button class="f-btn f-btn-primary f-btn-sm" type="submit" title="Approve this shop to take orders"><i data-lucide="check"></i> Approve</button>
                </form>
            @elseif ($isOpenHere)
                <a class="f-btn f-btn-warn f-btn-sm" href="{{ route('field.visit.show', $openVisit) }}"><i data-lucide="store"></i> Continue visit</a>
                @include('field.partials.leave-shop', [
                    'leaveVisit' => $openVisit,
                    'leaveRedirect' => 'shops',
                    'leaveClass' => 'f-btn f-btn-ghost f-btn-sm',
                ])
            @elseif ($openVisit)
                @include('field.partials.leave-shop', [
                    'leaveVisit' => $openVisit,
                    'leaveRedirect' => ($orderMode ?? false) ? 'orders' : 'shops',
                    'leaveClass' => 'f-btn f-btn-ghost f-btn-sm',
                    'leaveLabel' => 'Leave '.$openVisit->shop?->name.' first',
                ])
            @elseif ($orderMode ?? false)
                <form method="post" action="{{ route('field.shops.order', $shop) }}" data-checkin>
                    @csrf
                    <input type="hidden" name="latitude">
                    <input type="hidden" name="longitude">
                    <button class="f-btn f-btn-primary f-btn-sm" type="submit"><i data-lucide="shopping-bag"></i> Select shop</button>
                </form>
            @elseif (! empty($shop->is_mine))
                <form method="post" action="{{ route('field.shops.check-in', $shop) }}" data-checkin>
                    @csrf
                    <input type="hidden" name="purpose" value="Shop visit">
                    <input type="hidden" name="latitude">
                    <input type="hidden" name="longitude">
                    <button class="f-btn f-btn-primary f-btn-sm" type="submit"><i data-lucide="map-pin"></i> Visit</button>
                </form>
            @else
                <form method="post" action="{{ route('field.shops.order', $shop) }}" data-checkin>
                    @csrf
                    <input type="hidden" name="latitude">
                    <input type="hidden" name="longitude">
                    <button class="f-btn f-btn-primary f-btn-sm" type="submit"><i data-lucide="shopping-bag"></i> Order</button>
                </form>
            @endif
        </div>
    </td>
</tr>
