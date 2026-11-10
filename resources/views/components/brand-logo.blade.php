@props([
    'size' => 36,
    'showWordmark' => false,
    'wordmark' => null,
])
@php($brand = app(\App\Support\SiteContent::class))

<span {{ $attributes->class('brand-logo') }}>
    <img
        src="{{ $brand->logoUrl() }}"
        alt="{{ $brand->siteName() }}"
        width="{{ $size }}"
        height="{{ $size }}"
    >
    @if ($showWordmark)
        <span class="brand-wordmark">{{ $wordmark ?? $brand->siteName() }}</span>
    @endif
</span>
