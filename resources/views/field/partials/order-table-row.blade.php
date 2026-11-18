@php
    $s = $order->simpleStatus();
    $due = (float) $order->collectibleBalance();
    $href = route('field.orders.show', $order).($due > 0.009 ? '#collect-bill' : '');
    $whenShort = $order->submitted_at?->isToday()
        ? __('field.today_time', ['time' => $order->submitted_at->format('g:i A')])
        : $order->submitted_at?->format('d M');
    $statusLabel = $s['label'] === __('field.status.waiting_approval') ? __('field.waiting') : $s['label'];
@endphp
<tr class="is-link" onclick="window.location='{{ $href }}'">
    <td class="shop">
        <a class="strong" href="{{ $href }}">{{ $order->shop?->name ?? $order->number }}</a>
        <span class="sub f-table-orders-compact">{{ $order->number }} · {{ $whenShort }}</span>
        @if ($order->shop?->city)
            <span class="sub f-table-orders-wide">{{ $order->shop->city }}</span>
        @endif
    </td>
    <td class="f-table-orders-wide nowrap">{{ $order->number }}</td>
    <td class="f-table-orders-wide">
        {{ $order->submitted_at?->format('d M Y') }}
        <span class="sub">{{ $order->submitted_at?->format('g:i A') }}</span>
    </td>
    <td class="num strong">{{ \App\Support\DemoData::taka($order->total) }}</td>
    <td class="num">
        @if ($due > 0.009)
            <span class="f-baki-amt">{{ \App\Support\DemoData::taka($due) }}</span>
        @else
            <span class="f-muted">—</span>
        @endif
    </td>
    <td class="status"><span class="f-status {{ $s['tone'] }}">{{ $statusLabel }}</span></td>
</tr>
