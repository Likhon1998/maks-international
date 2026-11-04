@extends('layouts.app')
@section('title', 'Products')
@section('content')
    <x-page-header title="Products" subtitle="Catalogue" action="{{ route('products.create') }}" action-label="Add Product">
        <x-slot:description>Shops see only their price-group wholesale rate. Cost and landed cost stay admin-only.</x-slot:description>
        <a class="btn btn-ghost" href="{{ route('price-groups.index') }}">Price groups</a>
    </x-page-header>

    @if (session('success'))
        <div class="card" style="padding:12px 16px;margin-bottom:14px;background:#e8f8ee;color:#15803d">{{ session('success') }}</div>
    @endif

    <div class="card pcat-filters">
        <form class="filters" method="get">
            <input class="input" name="search" value="{{ request('search') }}" placeholder="Search name, SKU or barcode">
            <select class="select" name="category_id">
                <option value="">All categories</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}" @selected(request('category_id') == $category->id)>{{ $category->name }}</option>
                @endforeach
            </select>
            <select class="select" name="status">
                <option value="">All statuses</option>
                <option value="active" @selected(request('status') === 'active')>Active</option>
                <option value="inactive" @selected(request('status') === 'inactive')>Inactive</option>
                <option value="discontinued" @selected(request('status') === 'discontinued')>Discontinued</option>
            </select>
            <button class="btn btn-ghost" type="submit">Filter</button>
        </form>
    </div>

    <div class="card pcat-table">
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th class="col-hide-md">Category</th>
                        <th class="num">On hand</th>
                        <th class="num col-hide-lg">Available</th>
                        <th class="num">Wholesale</th>
                        <th class="num col-hide-lg">Landed</th>
                        <th>Status</th>
                        <th class="actions"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($products as $product)
                        @php
                            $available = $product->availableStock();
                            $stockClass = $available <= 0 ? 'stock-out' : ($available <= (int) $product->minimum_stock ? 'stock-low' : 'stock-ok');
                        @endphp
                        <tr>
                            <td>
                                <div class="product-cell">
                                    <x-product-thumb :product="$product" :size="56" />
                                    <div>
                                        @can('update', $product)
                                            <b><a href="{{ route('products.edit', $product) }}">{{ $product->name }}</a></b>
                                        @else
                                            <b>{{ $product->name }}</b>
                                        @endcan
                                        <small>{{ $product->sku }}@if ($product->brand) · {{ $product->brand->name }}@endif</small>
                                    </div>
                                </div>
                            </td>
                            <td class="col-hide-md">{{ $product->category?->name ?: '—' }}</td>
                            <td class="num">{{ number_format($product->stock_on_hand) }}</td>
                            <td class="num col-hide-lg {{ $stockClass }}">{{ number_format($available) }}</td>
                            <td class="num">{{ \App\Support\DemoData::taka($product->wholesale_price) }}</td>
                            <td class="num col-hide-lg">{{ \App\Support\DemoData::taka($product->landed_cost) }}</td>
                            <td><x-badge :status="$product->statusLabel()" /></td>
                            <td class="actions">
                                @can('update', $product)
                                    <x-row-menu>
                                        <a class="row-menu-item" href="{{ route('products.edit', $product) }}">Edit product</a>
                                    </x-row-menu>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="empty">
                                <div class="pcat-empty">
                                    <strong>No products yet</strong>
                                    <span class="muted">Add a SKU to start the catalogue.</span>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($products->hasPages())
            <div style="padding:14px">{{ $products->links() }}</div>
        @endif
    </div>
@endsection
