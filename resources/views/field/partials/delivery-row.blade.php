@php
    $when = $delivery->delivered_at ?? $delivery->dispatched_at;
    $whenLabel = $when
        ? ($when->isToday() ? 'Today '.$when->format('g:i A') : $when->format('d M Y'))
        : '—';
    $icon = match ($delivery->status) {
        \App\Models\Delivery::STATUS_DELIVERED => 'package-check',
        \App\Models\Delivery::STATUS_FAILED => 'package-x',
        default => 'truck',
    };
    $due = $delivery->order ? (float) $delivery->order->collectibleBalance() : 0;
    $tag = 'a';
    $href = ' href="'.e(route('field.deliveries.show', $delivery)).'"';
@endphp
<{{ $tag }} class="f-order"{{ $href }}>
    <div class="f-shop-ico tone-sky" style="width:40px;height:40px;flex:none">
        <i data-lucide="{{ $icon }}"></i>
    </div>
    <div class="f-order-main">
        <div class="f-order-top">
            <strong>{{ $delivery->shop?->name ?? $delivery->number }}</strong>
            @if ($delivery->order)
                <span class="f-order-amt">{{ \App\Support\DemoData::taka($delivery->order->total) }}</span>
            @endif
        </div>
        <div class="f-order-sub">
            <span>{{ $delivery->number }}{{ $delivery->order ? ' · '.$delivery->order->number : '' }} · {{ $whenLabel }}</span>
            <span class="f-status {{ $delivery->statusTone() }}">{{ $delivery->statusLabel() }}</span>
        </div>
        @if ($due > 0.009)
            <div class="f-collect-pill">Baki {{ \App\Support\DemoData::taka($due) }}</div>
        @endif
        @if ($delivery->assignee?->name || $delivery->delivery_address)
            <div class="f-muted f-small" style="margin-top:4px">
                @if ($delivery->assignee?->name)Rider {{ $delivery->assignee->name }}@endif
                @if ($delivery->assignee?->name && $delivery->delivery_address) · @endif
                @if ($delivery->delivery_address){{ \Illuminate\Support\Str::limit($delivery->delivery_address, 42) }}@endif
            </div>
        @endif
    </div>
    @if ($delivery->order)
        <i data-lucide="chevron-right" class="f-chev"></i>
    @endif
</{{ $tag }}>
