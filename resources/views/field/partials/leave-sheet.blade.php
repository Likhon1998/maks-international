@php
    $needsPhoto = $visit->needsPhotoEvidence();
    $leaveTo = request('to', $leaveRedirect ?? 'shops');
    $openLeave = request()->boolean('leave') || $errors->has('notes') || $errors->has('photo') || $errors->has('photo_captured');
@endphp
<div
    x-data="{
        open: {{ $openLeave ? 'true' : 'false' }},
        redirect: @js($leaveTo),
    }"
    @open-leave.window="open = true; if ($event.detail) redirect = $event.detail; $nextTick(() => document.getElementById('leave-notes')?.focus())"
>
    <div class="f-sheet-backdrop" x-show="open" x-cloak x-transition.opacity @click="open = false"></div>
    <form class="f-sheet" x-show="open" x-cloak x-transition method="post" action="{{ route('field.visit.leave', $visit) }}" enctype="multipart/form-data" @keydown.escape.window="open = false">
        @csrf
        <input type="hidden" name="redirect" :value="redirect">
        <div class="f-sheet-grip"></div>
        <h3>I'm leaving {{ $visit->shop?->name }}</h3>
        <p class="f-muted f-small" style="margin:0 0 12px">Adding a shop and visiting a shop are different. This visit needs a note@if ($needsPhoto) and a photo@endif.</p>

        @if ($visit->order_id)
            <input type="hidden" name="outcome" value="order_taken">
            <div class="f-chip green" style="margin-bottom:12px">Order already taken on this visit</div>
        @else
            <div class="f-choices">
                <label class="f-choice"><input type="radio" name="outcome" value="no_order" checked><span><i data-lucide="x-circle"></i>No order today</span></label>
                <label class="f-choice"><input type="radio" name="outcome" value="closed"><span><i data-lucide="lock"></i>Shop closed</span></label>
                <label class="f-choice"><input type="radio" name="outcome" value="follow_up"><span><i data-lucide="calendar-clock"></i>Follow up later</span></label>
            </div>
        @endif

        <label class="f-label" for="leave-notes">What did you talk about with the owner? <em class="as-req">*</em></label>
        <textarea id="leave-notes" class="f-textarea @error('notes') is-invalid @enderror" name="notes" rows="3" maxlength="1000" required placeholder="e.g. asked about charger stock, will order next week, owner was busy…">{{ old('notes', $visit->notes) }}</textarea>
        @error('notes')<div class="f-error">{{ $message }}</div>@enderror

        <x-live-camera-photo
            name="photo"
            :required="$needsPhoto"
            :title="$needsPhoto ? 'Take a live photo at this shop' : 'Live photo of the visit (optional)'"
            :hint="$needsPhoto ? 'Required reality check — camera only, gallery blocked.' : 'Camera only — gallery photos are blocked.'"
            class="f-cam-block"
        />
        @error('photo')<div class="f-error">{{ $message }}</div>@enderror
        @error('photo_captured')<div class="f-error">{{ $message }}</div>@enderror

        <button class="f-btn f-btn-primary f-btn-lg f-btn-block" style="margin-top:14px" type="submit"><i data-lucide="log-out"></i> Save visit &amp; leave</button>
        <button class="f-btn f-btn-ghost f-btn-block" style="margin-top:8px" type="button" @click="open = false">Stay at this shop</button>
    </form>
</div>
