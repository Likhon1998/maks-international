@extends('layouts.app')
@section('title', 'New return')
@section('content')
    <x-page-header title="New return" subtitle="Record a customer/shop return or warranty claim">
        <a class="btn btn-ghost" href="{{ route('returns.index') }}">Back</a>
    </x-page-header>

    @if ($errors->any())
        <div class="card" style="padding:12px 16px;margin-bottom:14px;background:#fef2f2;color:#b91c1c">
            <ul style="margin:0;padding-left:18px">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

    <form class="card" style="padding:20px" method="post" action="{{ route('returns.store') }}">
        @csrf
        <div class="form-grid" style="margin-bottom:16px">
            <div class="field">
                <label class="label">Shop</label>
                <select class="select" name="shop_id" required>
                    <option value="">Select shop</option>
                    @foreach ($shops as $shop)
                        <option value="{{ $shop->id }}" @selected(old('shop_id') == $shop->id)>{{ $shop->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label class="label">Delivered order</label>
                <select class="select" name="order_id" required>
                    <option value="">Select order</option>
                    @foreach ($orders as $order)
                        <option value="{{ $order->id }}" @selected(old('order_id') == $order->id)>{{ $order->number }} · {{ $order->shop?->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label class="label">Reason type</label>
                <select class="select" name="reason_type" required>
                    @foreach (['warranty' => 'Warranty', 'defect' => 'Defective', 'damaged' => 'Damaged', 'wrong_item' => 'Wrong product', 'not_accepted' => 'Customer return', 'other' => 'Other'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('reason_type', 'warranty') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field" style="grid-column:1/-1">
                <label class="label">Details</label>
                <textarea class="input" name="reason" rows="2">{{ old('reason') }}</textarea>
            </div>
            <label style="display:flex;gap:8px;align-items:center;font-size:13px;grid-column:1/-1">
                <input type="checkbox" name="restock" value="1" @checked(old('restock', true))> Restock to warehouse on approval
            </label>
        </div>

        <div style="font-weight:700;margin-bottom:4px">Return lines</div>
        <div class="muted" style="font-size:12px;margin-bottom:10px">Only products on the selected order can be returned, up to the quantity sold. Credit is given at the price charged on that order.</div>
        <div class="table-wrap" style="margin-bottom:16px">
            <table class="data">
                <thead><tr><th>Product</th><th>Qty</th></tr></thead>
                <tbody>
                    @foreach ($products as $product)
                        <tr>
                            <td>
                                <div style="font-weight:600">{{ $product->name }}</div>
                                <div class="muted" style="font-size:12px">{{ $product->sku }} · {{ \App\Support\DemoData::taka($product->wholesale_price) }}</div>
                                <input type="hidden" name="items[{{ $loop->index }}][product_id]" value="{{ $product->id }}">
                            </td>
                            <td><input class="input" style="width:90px" type="number" min="0" name="items[{{ $loop->index }}][quantity]" value="{{ old('items.'.$loop->index.'.quantity', 0) }}"></td>                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <button class="btn btn-primary" type="submit">Submit return</button>
    </form>
@endsection
