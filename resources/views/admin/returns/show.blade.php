@extends('layouts.app')
@section('title', $return->number)
@section('content')
    <x-page-header title="{{ $return->number }}" subtitle="{{ $return->shop?->name }} · {{ $return->statusLabel() }}">
        <a class="btn btn-ghost" href="{{ route('returns.index') }}">Back</a>
    </x-page-header>

    @if (session('success'))
        <div class="card" style="padding:12px 16px;margin-bottom:14px;background:#e8f8ee;color:#15803d">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div class="card" style="padding:12px 16px;margin-bottom:14px;background:#fef2f2;color:#b91c1c">{{ $errors->first() }}</div>
    @endif

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:14px">
        <div class="card" style="padding:14px"><div class="muted" style="font-size:12px">Shop</div><div style="font-weight:700">{{ $return->shop?->name }}</div></div>
        <div class="card" style="padding:14px"><div class="muted" style="font-size:12px">Order</div><div style="font-weight:700">{{ $return->order?->number ?: '—' }}</div></div>
        <div class="card" style="padding:14px"><div class="muted" style="font-size:12px">Salesman</div><div style="font-weight:700">{{ $return->order?->salesman?->name ?: ($return->creator?->name ?: '—') }}</div></div>
        <div class="card" style="padding:14px"><div class="muted" style="font-size:12px">Return date</div><div style="font-weight:700">{{ $return->created_at?->format('d M Y H:i') }}</div></div>
        <div class="card" style="padding:14px"><div class="muted" style="font-size:12px">Reason</div><div style="font-weight:700">{{ $return->reasonTypeLabel() }}</div></div>
        <div class="card" style="padding:14px"><div class="muted" style="font-size:12px">Qty</div><div style="font-weight:700">{{ $return->totalQuantity() }}</div></div>
        <div class="card" style="padding:14px"><div class="muted" style="font-size:12px">Credit</div><div style="font-weight:700">{{ \App\Support\DemoData::taka($return->total) }}</div></div>
        <div class="card" style="padding:14px"><div class="muted" style="font-size:12px">Credit issued</div><div style="font-weight:700">{{ $return->credit_issued ? 'Yes' : 'No' }}</div></div>
    </div>

    @if ($return->isPending())
        <div class="card" style="padding:16px;margin-bottom:14px;border:1px solid #fed7aa;background:#fffbeb">
            <div style="font-weight:700;margin-bottom:6px;color:#9a3412">Verify return</div>
            <p class="muted" style="margin:0 0 12px;font-size:13px">Inventory will not change until you approve and add stock. Prefer “Approve &amp; add to inventory” for a single step.</p>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
                @can('returns.approve')
                    <div style="display:grid;gap:10px">
                        <form method="post" action="{{ route('returns.approve', $return) }}">
                            @csrf
                            <input type="hidden" name="restock_now" value="1">
                            <div class="field" style="margin-bottom:10px">
                                <label class="label">Resolution notes (optional)</label>
                                <textarea class="input" name="resolution_notes" rows="2">{{ old('resolution_notes') }}</textarea>
                            </div>
                            <button class="btn btn-primary" type="submit">Approve &amp; add to inventory</button>
                        </form>
                        <form method="post" action="{{ route('returns.approve', $return) }}">
                            @csrf
                            <input type="hidden" name="restock_now" value="0">
                            <button class="btn btn-ghost" type="submit">Approve only (credit, no stock yet)</button>
                        </form>
                    </div>
                @endcan
                @can('returns.reject')
                    <form method="post" action="{{ route('returns.reject', $return) }}">
                        @csrf
                        <div class="field" style="margin-bottom:10px">
                            <label class="label">Rejection reason</label>
                            <textarea class="input" name="resolution_notes" rows="2" required>{{ old('resolution_notes') }}</textarea>
                        </div>
                        <button class="btn btn-ghost" type="submit" style="color:#b91c1c;border-color:#fecaca">Reject</button>
                    </form>
                @endcan
            </div>
        </div>
    @endif

    @if ($return->isApproved())
        <div class="card" style="padding:16px;margin-bottom:14px;border:1px solid #bbf7d0;background:#f0fdf4">
            <div style="font-weight:700;margin-bottom:6px;color:#166534">Approved — ready for inventory</div>
            <p class="muted" style="margin:0 0 12px;font-size:13px">Credit is issued. Add these quantities back to the main warehouse when you have verified the physical goods.</p>
            @can('returns.approve')
                <form method="post" action="{{ route('returns.restock', $return) }}">
                    @csrf
                    <button class="btn btn-primary" type="submit">Add to inventory</button>
                </form>
            @endcan
        </div>
    @endif

    @if ($return->isRestocked())
        <div class="card" style="padding:12px 16px;margin-bottom:14px;background:#e8f8ee;color:#15803d">
            Added to inventory {{ $return->restocked_at?->format('d M Y H:i') }}
            @if ($return->restocker) · {{ $return->restocker->name }} @endif
        </div>
    @endif

    @if ($return->resolution_notes)
        <div class="card" style="padding:12px 16px;margin-bottom:14px">
            <span class="muted">Resolution:</span> {{ $return->resolution_notes }}
            @if ($return->approver)
                <span class="muted"> · {{ $return->approver->name }} · {{ $return->approved_at?->format('d M Y H:i') }}</span>
            @endif
        </div>
    @endif

    @if ($return->reason)
        <div class="card" style="padding:12px 16px;margin-bottom:14px"><span class="muted">Details:</span> {{ $return->reason }}</div>
    @endif

    <div class="card">
        <div style="padding:14px;font-weight:700;border-bottom:1px solid #eee">Returned products</div>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>SKU</th><th>Product</th><th>Qty</th><th>Unit</th><th>Line</th></tr></thead>
                <tbody>
                    @foreach ($return->items as $item)
                        <tr>
                            <td>{{ $item->product_sku }}</td>
                            <td>{{ $item->product_name }}</td>
                            <td style="font-weight:700">{{ $item->quantity }}</td>
                            <td>{{ \App\Support\DemoData::taka($item->unit_price) }}</td>
                            <td style="font-weight:600">{{ \App\Support\DemoData::taka($item->line_total) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
