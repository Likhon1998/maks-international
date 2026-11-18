@php
    $outstanding = (float) $shop->fieldOutstanding();
    $phone = preg_replace('/[^0-9+]/', '', (string) $shop->phone);
    $mapQuery = urlencode(trim(implode(', ', array_filter([$shop->name, $shop->address, $shop->city]))));
    $isOpenHere = $openVisit && $openVisit->shop_id === $shop->id;
    $tone = ['violet', 'blue', 'green', 'amber', 'rose', 'sky', 'teal'][crc32((string) $shop->name) % 7];
@endphp
<div class="f-shop {{ $shop->visited_today && ! $isOpenHere ? 'done' : '' }}"
    data-search="{{ strtolower($shop->name.' '.$shop->code.' '.$shop->owner_name.' '.$shop->city.' '.$shop->territory?->name.' '.($shop->area_label ?? '')) }}"
    data-state="{{ $shop->visited_today ? 'done' : 'todo' }}"
    data-owner="{{ ! empty($shop->is_mine) ? 'mine' : 'other' }}"
    data-area="{{ $shop->area_label ?? ($shop->city ?: $shop->territory?->name ?: 'No area') }}">
    <div class="f-shop-head">
        <div class="f-shop-ico tone-{{ $tone }} {{ $shop->visited_today ? 'done' : '' }}">
            @if ($shop->visited_today)
                <i data-lucide="check"></i>
            @else
                {{ strtoupper(mb_substr($shop->name, 0, 1)) }}
            @endif
        </div>
        <div style="flex:1;min-width:0">
            <div class="f-shop-name">{{ $shop->name }}</div>
            <div class="f-shop-meta">{{ $shop->owner_name }}{{ $shop->city || $shop->territory ? ' · '.($shop->city ?: $shop->territory?->name) : '' }}</div>
            <div class="f-chips">
                @if ($isOpenHere)
                    <span class="f-chip amber">{{ __('field.im_here') }}</span>
                @elseif ($shop->visited_today)
                    <span class="f-chip green">{{ __('field.visited_today') }}</span>
                @elseif ($shop->last_visit_at)
                    <span class="f-chip">{{ __('field.last_visit_ago', ['when' => $shop->last_visit_at->diffForHumans(null, true)]) }}</span>
                @else
                    <span class="f-chip amber">{{ __('field.never_visited') }}</span>
                @endif
                @if ($shop->status === \App\Models\Shop::STATUS_PENDING)
                    <span class="f-chip amber">{{ __('field.awaiting_approval') }}</span>
                @else
                    <span class="f-chip {{ $outstanding > 0 ? 'red' : '' }}">{{ __('field.baki') }} {{ \App\Support\DemoData::taka($outstanding) }}</span>
                @endif
                @if (! empty($shop->is_mine))
                    <span class="f-chip green">{{ __('field.my_shop') }}</span>
                @elseif ($shop->assignedSalesman)
                    <span class="f-chip">{{ $shop->assignedSalesman->name }}</span>
                @else
                    <span class="f-chip amber">{{ __('field.unassigned') }}</span>
                @endif
                @if ($shop->priceGroup)
                    <span class="f-chip">{{ $shop->priceGroup->name }}</span>
                @endif
            </div>
        </div>
    </div>
    <div class="f-shop-actions">
        @if ($phone)
            <a class="f-btn f-btn-ghost f-btn-icon" href="tel:{{ $phone }}" aria-label="{{ __('field.call') }} {{ $shop->owner_name ?: $shop->name }}"><i data-lucide="phone"></i></a>
        @endif
        <a class="f-btn f-btn-ghost f-btn-icon" href="https://www.google.com/maps/search/?api=1&query={{ $mapQuery }}" target="_blank" rel="noopener" aria-label="{{ __('field.directions') }} {{ $shop->name }}"><i data-lucide="map-pin"></i></a>
        @if ($isOpenHere)
            <a class="f-btn f-btn-warn" style="flex:1" href="{{ route('field.visit.show', $openVisit) }}"><i data-lucide="store"></i> {{ __('field.continue_visit') }}</a>
            @include('field.partials.leave-shop', [
                'leaveVisit' => $openVisit,
                'leaveRedirect' => 'shops',
                'leaveClass' => 'f-btn f-btn-ghost',
            ])
        @elseif ($openVisit)
            @include('field.partials.leave-shop', [
                'leaveVisit' => $openVisit,
                'leaveRedirect' => 'shops',
                'leaveClass' => 'f-btn f-btn-ghost',
                'leaveLabel' => __('field.leave_shop_first', ['shop' => $openVisit->shop?->name]),
            ])
        @elseif (! empty($shop->is_mine))
            <form method="post" action="{{ route('field.shops.check-in', $shop) }}" data-checkin style="flex:1">
                @csrf
                <input type="hidden" name="purpose" value="Shop visit">
                <input type="hidden" name="latitude">
                <input type="hidden" name="longitude">
                <button class="f-btn f-btn-primary" style="width:100%" type="submit"><i data-lucide="map-pin"></i> {{ __('field.visit') }}</button>
            </form>
        @else
            <form method="post" action="{{ route('field.shops.order', $shop) }}" data-checkin style="flex:1">
                @csrf
                <input type="hidden" name="latitude">
                <input type="hidden" name="longitude">
                <button class="f-btn f-btn-primary" style="width:100%" type="submit"><i data-lucide="shopping-bag"></i> {{ __('field.order_action') }}</button>
            </form>
        @endif
    </div>
</div>
