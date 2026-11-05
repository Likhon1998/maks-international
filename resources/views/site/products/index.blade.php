@extends('site.layouts.app')
@section('title', 'Products')
@section('meta', 'Browse every product from '.$site->siteName().' and order on MAKS GADGET.')
@section('content')
    <section class="page-hero page-hero-compact">
        <div class="site-wrap">
            <span class="eyebrow">Catalogue</span>
            <h1>All products</h1>
            <p class="lead">Browse the full catalogue. Click Order to buy on the official MAKS GADGET website.</p>
        </div>
    </section>

    <section class="section section-catalog">
        <div class="site-wrap">
            <form class="site-product-filters" method="get" action="{{ route('site.products') }}">
                <input class="site-input" type="search" name="q" value="{{ $search }}" placeholder="Search name or SKU">
                <select class="site-input" name="category">
                    <option value="">All categories</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected((int) $categoryId === (int) $category->id)>{{ $category->name }}</option>
                    @endforeach
                </select>
                <button class="btn btn-outline" type="submit">Filter</button>
            </form>

            <p class="muted small" style="margin:0 0 12px">{{ $products->count() }} {{ \Illuminate\Support\Str::plural('product', $products->count()) }}</p>

            <div class="site-product-grid">
                @forelse ($products as $product)
                    @include('site.products._card', ['product' => $product])
                @empty
                    <p class="muted">No products match that search.</p>
                @endforelse
            </div>
        </div>
    </section>
@endsection
