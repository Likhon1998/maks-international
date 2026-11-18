@php
    $needsPhoto = $visit->needsPhotoEvidence();
    $leaveTo = request('to', $leaveRedirect ?? 'shops');
    $openLeave = request()->boolean('leave') || $errors->has('notes') || $errors->has('photo') || $errors->has('photo_captured');
    $oldOutcome = old('outcome', $visit->order_id ? 'order_taken' : 'no_order');
@endphp
<div
    class="f-leave"
    x-data="{
        open: {{ $openLeave ? 'true' : 'false' }},
        redirect: @js($leaveTo),
        outcome: @js($oldOutcome),
        photoRequired: {{ $needsPhoto ? 'true' : 'false' }},
        get mistake() { return this.outcome === 'cancelled'; },
        get shopClosed() { return this.outcome === 'closed'; },
        get evidenceOptional() { return this.mistake || this.shopClosed; },
        get needsTalk() { return ! this.evidenceOptional; },
        get needsShot() { return this.photoRequired && this.needsTalk; },
        openSheet(to) {
            if (to) this.redirect = to;
            this.open = true;
            document.body.classList.add('f-sheet-open');
            this.$nextTick(() => {
                if (window.lucide) lucide.createIcons();
                // Delay focus so the sheet finishes sliding up on phones.
                if (this.needsTalk) {
                    setTimeout(() => this.$refs.notes?.focus({ preventScroll: true }), 280);
                }
            });
        },
        closeSheet() {
            this.open = false;
            document.body.classList.remove('f-sheet-open');
        },
        beforeSubmit(e) {
            if (this.mistake) return true;
            const notes = (this.$refs.notes?.value || '').trim();
            if (this.needsTalk && notes === '') {
                e.preventDefault();
                this.$refs.notes?.focus();
                return false;
            }
            if (this.needsShot) {
                const captured = this.$el.querySelector('input[name=photo_captured]')?.value === '1';
                if (! captured) {
                    e.preventDefault();
                    this.$el.querySelector('.f-cam-launch')?.click();
                    return false;
                }
            }
            return true;
        }
    }"
    @open-leave.window="openSheet($event.detail)"
>
    <div class="f-sheet-backdrop" x-show="open" x-cloak x-transition.opacity @click="closeSheet()"></div>
    <form
        class="f-sheet f-leave-sheet"
        x-show="open"
        x-cloak
        x-transition
        method="post"
        action="{{ route('field.visit.leave', $visit) }}"
        enctype="multipart/form-data"
        @submit="beforeSubmit($event)"
        @keydown.escape.window="closeSheet()"
    >
        @csrf
        <input type="hidden" name="redirect" :value="redirect">
        <div class="f-sheet-grip"></div>
        <header class="f-leave-head">
            <h3>{{ __('field.im_leaving', ['shop' => $visit->shop?->name]) }}</h3>
            <button type="button" class="f-leave-close" @click="closeSheet()" aria-label="{{ __('field.stay_at_shop') }}">×</button>
        </header>

        @if ($visit->order_id)
            <input type="hidden" name="outcome" value="order_taken">
            <div class="f-chip green f-leave-chip">{{ __('field.order_already_on_visit') }}</div>
        @else
            <div class="f-choices f-choices-leave" role="radiogroup" aria-label="Visit outcome">
                <label class="f-choice">
                    <input type="radio" name="outcome" value="no_order" x-model="outcome">
                    <span><i data-lucide="message-circle"></i>{{ __('field.no_order_today') }}</span>
                </label>
                <label class="f-choice">
                    <input type="radio" name="outcome" value="follow_up" x-model="outcome">
                    <span><i data-lucide="calendar-clock"></i>{{ __('field.follow_up_later') }}</span>
                </label>
                <label class="f-choice">
                    <input type="radio" name="outcome" value="closed" x-model="outcome">
                    <span><i data-lucide="lock"></i>{{ __('field.shop_closed') }}</span>
                </label>
                <label class="f-choice">
                    <input type="radio" name="outcome" value="cancelled" x-model="outcome">
                    <span><i data-lucide="undo-2"></i>{{ __('field.opened_by_mistake') }}</span>
                </label>
            </div>
        @endif

        <p class="f-leave-hint" x-show="mistake" x-cloak>{{ __('field.leave_mistake_hint') }}</p>
        <p class="f-leave-hint" x-show="shopClosed" x-cloak>{{ __('field.leave_closed_hint') }}</p>
        <p class="f-leave-hint" x-show="needsTalk && ! {{ $visit->order_id ? 'true' : 'false' }}" x-cloak>
            {{ $needsPhoto ? __('field.leave_needs_note_photo') : __('field.leave_needs_note') }}
        </p>

        <div class="f-leave-evidence" x-show="!mistake" x-cloak>
            <label class="f-label" for="leave-notes">
                <span x-text="shopClosed ? @js(__('field.talk_notes_optional_label')) : @js(__('field.talk_notes_label'))"></span>
                <em class="as-req" x-show="needsTalk">*</em>
            </label>
            <textarea
                id="leave-notes"
                class="f-textarea @error('notes') is-invalid @enderror"
                name="notes"
                rows="3"
                maxlength="1000"
                x-ref="notes"
                :required="needsTalk"
                :placeholder="shopClosed ? @js(__('field.closed_notes_placeholder')) : @js(__('field.talk_notes_placeholder'))"
            >{{ old('notes', $visit->notes) }}</textarea>
            @error('notes')<div class="f-error">{{ $message }}</div>@enderror

            <div class="f-leave-cam" x-show="!mistake">
                <x-live-camera-photo
                    name="photo"
                    :required="false"
                    :title="$needsPhoto ? __('field.live_photo_required') : __('field.live_photo_optional')"
                    hint=""
                    class="f-cam-block"
                />
                <p class="f-leave-cam-note" x-text="needsShot ? @js(__('field.live_photo_required_hint')) : @js(__('field.live_photo_optional_hint'))"></p>
            </div>
            @error('photo')<div class="f-error">{{ $message }}</div>@enderror
            @error('photo_captured')<div class="f-error">{{ $message }}</div>@enderror
        </div>

        <div class="f-leave-actions">
            <button class="f-btn f-btn-primary f-btn-lg f-btn-block" type="submit">
                <i data-lucide="log-out"></i>
                <span x-text="mistake ? @js(__('field.cancel_visit')) : @js(__('field.save_visit_leave'))"></span>
            </button>
            <button class="f-btn f-btn-ghost f-btn-block" type="button" @click="closeSheet()">{{ __('field.stay_at_shop') }}</button>
        </div>
    </form>
</div>
