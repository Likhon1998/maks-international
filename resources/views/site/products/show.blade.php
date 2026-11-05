@extends('site.layouts.app')
@section('title', $product->name)
@section('meta', $product->description ?: $product->name)
@php
    $images = $product->imageUrls();
    $price = $product->publicPrice();
@endphp
@section('content')
    <section class="sp-page">
        <div class="site-wrap">
            <nav class="sp-crumb" aria-label="Breadcrumb">
                <a href="{{ route('site.home') }}">Home</a>
                <span aria-hidden="true">/</span>
                <a href="{{ route('site.products') }}">Products</a>
                <span aria-hidden="true">/</span>
                <span>{{ $product->name }}</span>
            </nav>

            <div
                class="sp-layout"
                @if ($images)
                    x-data="{ active: 0, images: @js(array_values($images)) }"
                    @keydown.right.window="active = (active + 1) % images.length"
                    @keydown.left.window="active = (active - 1 + images.length) % images.length"
                @endif
            >
                <div class="sp-gallery">
                    <div class="sp-stage">
                        @if ($images)
                            <img
                                src="{{ $images[0] }}"
                                :src="images[active]"
                                alt="{{ $product->name }}"
                                loading="eager"
                            >
                            <span class="sp-count" x-show="images.length > 1" x-cloak x-text="(active + 1) + ' / ' + images.length"></span>
                            <template x-if="images.length > 1">
                                <div class="sp-nav">
                                    <button type="button" class="sp-nav-btn" @click="active = (active - 1 + images.length) % images.length" aria-label="Previous photo">‹</button>
                                    <button type="button" class="sp-nav-btn" @click="active = (active + 1) % images.length" aria-label="Next photo">›</button>
                                </div>
                            </template>
                        @else
                            <div class="site-product-placeholder is-large">{{ mb_strtoupper(mb_substr($product->name, 0, 1)) }}</div>
                        @endif
                    </div>

                    @if (count($images) > 1)
                        <div class="sp-thumbs" role="list">
                            @foreach ($images as $index => $url)
                                <button
                                    type="button"
                                    class="sp-thumb"
                                    :class="active === {{ $index }} && 'is-on'"
                                    @click="active = {{ $index }}"
                                    aria-label="View photo {{ $index + 1 }}"
                                >
                                    <img src="{{ $url }}" alt="" loading="lazy">
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="sp-buy">
                    <div class="sp-meta">
                        @if ($product->category)<span>{{ $product->category->name }}</span>@endif
                        @if ($product->brand)<span>{{ $product->brand->name }}</span>@endif
                        <span>SKU {{ $product->sku }}</span>
                    </div>
                    <h1>{{ $product->name }}</h1>
                    @if ($price)
                        <div class="sp-price">{{ \App\Support\DemoData::taka($price) }}</div>
                    @endif
                    @if ($product->description)
                        <p class="sp-copy">{{ $product->description }}</p>
                    @endif

                    @if ($product->model || $product->warranty || $product->brand)
                        <dl class="sp-facts">
                            @if ($product->model)
                                <div><dt>Model</dt><dd>{{ $product->model }}</dd></div>
                            @endif
                            @if ($product->warranty)
                                <div><dt>Warranty</dt><dd>{{ $product->warranty }}</dd></div>
                            @endif
                            @if ($product->brand)
                                <div><dt>Brand</dt><dd>{{ $product->brand->name }}</dd></div>
                            @endif
                        </dl>
                    @endif

                    @php $orderExternal = app(\App\Support\SiteContent::class)->orderOpensExternally(); @endphp
                    <a class="btn btn-primary btn-lg sp-cta" href="{{ $product->publicOrderUrl() }}" @if($orderExternal) target="_blank" rel="noopener noreferrer" @endif>
                        {{ $orderExternal ? 'Order on MAKS GADGET' : 'Become a partner to order' }}
                    </a>
                    <p class="muted small">
                        {{ $orderExternal
                            ? 'You will be taken to the official MAKS GADGET website to place this order.'
                            : 'Wholesale ordering is for approved partners. Apply once, then order from your shop portal.' }}
                    </p>
                </div>
            </div>

            @if ($related->isNotEmpty())
                <div class="sp-more">
                    <div class="sp-more-head">
                        <h2>More products</h2>
                        <a class="link small" href="{{ route('site.products') }}">All products</a>
                    </div>
                    <div class="site-product-grid">
                        @foreach ($related as $item)
                            @include('site.products._card', ['product' => $item])
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </section>
@endsection
