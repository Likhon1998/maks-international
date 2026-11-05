@extends('site.layouts.app')
@section('title', 'Home')
@php
    $c = $site->section('home');
    $showPartner = $site->enabled('general.show_partner_button');
    $heroImage = $site->imageUrl($c['hero_image']);
    $rows = $c['status_card_rows'];
    $categories = $c['show_categories'] ? $site->categories() : collect();
    $stats = $c['show_stats'] ? $site->stats() : [];
    $featured = $site->catalogProducts(null, null, 8);
@endphp
@section('content')
    <section class="hero">
        <div class="site-wrap hero-grid">
            <div class="hero-copy">
                @if ($c['hero_eyebrow'])<span class="eyebrow">{{ $c['hero_eyebrow'] }}</span>@endif
                <h1>{{ $c['hero_title'] }}</h1>
                @if ($c['hero_lead'])<p class="lead">{{ $c['hero_lead'] }}</p>@endif
                <div class="hero-actions">
                    @if ($showPartner && $c['primary_button'])
                        <a class="btn btn-primary btn-lg" href="{{ route('site.partner') }}">{{ $c['primary_button'] }}</a>
                    @endif
                    <a class="btn btn-outline btn-lg" href="{{ route('site.products') }}">View products</a>
                </div>
                @if ($c['hero_points'])
                    <ul class="hero-points">
                        @foreach ($c['hero_points'] as $point)
                            <li>{{ $point }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
            @if ($heroImage)
                <div class="hero-media">
                    <img src="{{ $heroImage }}" alt="{{ $site->siteName() }}" loading="eager">
                    @if ($c['show_status_card'] && $rows)
                        <div class="hero-card">
                            @if ($c['status_card_title'])<div class="hero-card-title">{{ $c['status_card_title'] }}</div>@endif
                            @foreach ($rows as $row)
                                <div class="hero-card-row"><span class="dot {{ $loop->last ? 'dot-active' : 'dot-done' }}"></span>{{ $row }}</div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif
        </div>
    </section>

    @if ($stats)
        <section class="site-stats-band">
            <div class="site-wrap site-stats">
                @foreach ($stats as $stat)
                    <div class="site-stat">
                        <strong>{{ number_format($stat['value']) }}</strong>
                        <span>{{ $stat['label'] }}</span>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    @if ($c['steps'])
        <section class="section">
            <div class="site-wrap">
                <div class="section-head">
                    @if ($c['steps_eyebrow'])<span class="eyebrow">{{ $c['steps_eyebrow'] }}</span>@endif
                    @if ($c['steps_title'])<h2>{{ $c['steps_title'] }}</h2>@endif
                    @if ($c['steps_lead'])<p class="lead">{{ $c['steps_lead'] }}</p>@endif
                </div>
                <div class="steps">
                    @foreach ($c['steps'] as $step)
                        <div class="step">
                            <span class="step-num">{{ $loop->iteration }}</span>
                            <h3>{{ $step['title'] }}</h3>
                            <p>{{ $step['text'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    @if ($categories->isNotEmpty())
        <section class="section section-soft">
            <div class="site-wrap">
                <div class="section-head">
                    @if ($c['categories_eyebrow'])<span class="eyebrow">{{ $c['categories_eyebrow'] }}</span>@endif
                    @if ($c['categories_title'])<h2>{{ $c['categories_title'] }}</h2>@endif
                    @if ($c['categories_lead'])<p class="lead">{{ $c['categories_lead'] }}</p>@endif
                </div>
                <div class="site-categories">
                    @foreach ($categories as $category)
                        <a class="site-category" href="{{ route('site.products', ['category' => $category->id]) }}">
                            @if ($category->logoUrl())
                                <span class="feature-icon site-category-logo"><img src="{{ $category->logoUrl() }}" alt=""></span>
                            @else
                                <span class="feature-icon">@include('site.partials.icon', ['name' => 'box'])</span>
                            @endif
                            <div>
                                <h3>{{ $category->name }}</h3>
                                <p>{{ $category->products_count }} {{ \Illuminate\Support\Str::plural('product', $category->products_count) }}</p>
                            </div>
                        </a>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    @if ($featured->isNotEmpty())
        <section class="section">
            <div class="site-wrap">
                <div class="section-head">
                    <span class="eyebrow">Catalogue</span>
                    <h2>All products, ready to order.</h2>
                    <p class="lead">Every item in our catalogue is listed here. Order on the official MAKS GADGET website.</p>
                </div>
                <div class="site-product-grid">
                    @foreach ($featured as $product)
                        @include('site.products._card', ['product' => $product])
                    @endforeach
                </div>
                <div class="catalog-more">
                    <a class="btn btn-outline" href="{{ route('site.products') }}">View all products</a>
                </div>
            </div>
        </section>
    @endif

    @if ($c['features'])
        <section class="section {{ $categories->isNotEmpty() ? '' : 'section-soft' }}">
            <div class="site-wrap">
                <div class="section-head">
                    @if ($c['features_eyebrow'])<span class="eyebrow">{{ $c['features_eyebrow'] }}</span>@endif
                    @if ($c['features_title'])<h2>{{ $c['features_title'] }}</h2>@endif
                </div>
                <div class="features">
                    @foreach ($c['features'] as $feature)
                        <div class="feature">
                            <span class="feature-icon">@include('site.partials.icon', ['name' => $feature['icon'] ?? 'check'])</span>
                            <h3>{{ $feature['title'] }}</h3>
                            <p>{{ $feature['text'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    @if ($c['cta_title'])
        <section class="section">
            <div class="site-wrap">
                <div class="cta-band">
                    <div>
                        <h2>{{ $c['cta_title'] }}</h2>
                        @if ($c['cta_text'])<p>{{ $c['cta_text'] }}</p>@endif
                    </div>
                    <div class="cta-band-actions">
                        @if ($showPartner)
                            <a class="btn btn-white btn-lg" href="{{ route('site.partner') }}">Become a partner</a>
                        @endif
                        <a class="btn btn-ghost-light btn-lg" href="{{ route('site.contact') }}">Contact sales</a>
                    </div>
                </div>
            </div>
        </section>
    @endif
@endsection
