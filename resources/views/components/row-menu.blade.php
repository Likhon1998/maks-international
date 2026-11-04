@props([
    'label' => 'Actions',
])

<div
    {{ $attributes->class('row-menu') }}
    x-data="{ open: false }"
    @keydown.escape.window="open = false"
>
    <button
        type="button"
        class="row-menu-btn"
        @click="open = !open"
        :aria-expanded="open.toString()"
        aria-haspopup="menu"
        title="{{ $label }}"
    >
        <span aria-hidden="true">⋯</span>
        <span class="sr-only">{{ $label }}</span>
    </button>
    <div
        class="row-menu-panel"
        role="menu"
        x-cloak
        x-show="open"
        x-transition.origin.top.right
        @click.outside="open = false"
        @click="open = false"
    >
        {{ $slot }}
    </div>
</div>
