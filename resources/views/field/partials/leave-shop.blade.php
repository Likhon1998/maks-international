@php
    $leaveVisit = $leaveVisit ?? $openVisit ?? $fieldOpenVisit ?? null;
    $leaveRedirect = $leaveRedirect ?? 'shops';
    $leaveClass = $leaveClass ?? 'f-btn f-btn-ghost f-btn-sm';
    $leaveLabel = $leaveLabel ?? __('field.im_leaving_btn');
    $onThisVisit = $leaveVisit && request()->routeIs('field.visit.show') && optional(request()->route('visit'))->id === $leaveVisit->id;
@endphp
@if ($leaveVisit)
    @if ($onThisVisit)
        <button type="button" class="{{ $leaveClass }}" title="Check out of this shop"
            onclick="window.dispatchEvent(new CustomEvent('open-leave', { detail: {{ json_encode($leaveRedirect) }} }))">
            <i data-lucide="log-out"></i> {{ $leaveLabel }}
        </button>
    @else
        <a class="{{ $leaveClass }}" href="{{ route('field.visit.show', $leaveVisit) }}?leave=1&to={{ urlencode($leaveRedirect) }}" title="Check out of this shop">
            <i data-lucide="log-out"></i> {{ $leaveLabel }}
        </a>
    @endif
@endif
