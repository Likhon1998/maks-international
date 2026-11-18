@php
    $locale = app()->getLocale();
    $next = $locale === 'bn' ? 'en' : 'bn';
    $label = $locale === 'bn' ? __('field.lang_en') : __('field.lang_bn');
@endphp
<form class="f-lang {{ $class ?? '' }}" method="post" action="{{ route('field.locale') }}" title="{{ __('field.switch_language') }}">
    @csrf
    <input type="hidden" name="locale" value="{{ $next }}">
    <button type="submit" class="f-lang-btn" aria-label="{{ __('field.switch_language') }}: {{ $label }}">
        <span class="f-lang-cur">{{ $locale === 'bn' ? __('field.lang_bn') : __('field.lang_en') }}</span>
        <span class="f-lang-sep" aria-hidden="true">/</span>
        <span class="f-lang-next">{{ $label }}</span>
    </button>
</form>
