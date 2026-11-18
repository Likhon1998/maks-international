@php
    $needsPhoto = $visit->needsPhotoEvidence();
    $leaveTo = request('to', $leaveRedirect ?? 'shops');
    $openLeave = request()->boolean('leave') || $errors->has('notes') || $errors->has('photo') || $errors->has('photo_captured');
    $oldOutcome = old('outcome', $visit->order_id ? 'order_taken' : 'no_order');
@endphp
<div
    x-data="{
        open: {{ $openLeave ? 'true' : 'false' }},
        redirect: @js($leaveTo),
        outcome: @js($oldOutcome),
        get mistake() { return this.outcome === 'cancelled'; },
        get shopClosed() { return this.outcome === 'closed'; },
        get evidenceOptional() { return this.mistake || this.shopClosed; }
    }"
    @open-leave.window="open = true; if ($event.detail) redirect = $event.detail; $nextTick(() => { if (!this.evidenceOptional) document.getElementById('leave-notes')?.focus(); })"
>
    <div class="f-sheet-backdrop" x-show="open" x-cloak x-transition.opacity @click="open = false"></div>
    <form class="f-sheet" x-show="open" x-cloak x-transition method="post" action="{{ route('field.visit.leave', $visit) }}" enctype="multipart/form-data" @keydown.escape.window="open = false">
        @csrf
        <input type="hidden" name="redirect" :value="redirect">
        <div class="f-sheet-grip"></div>
        <h3>{{ __('field.im_leaving', ['shop' => $visit->shop?->name]) }}</h3>
        <p class="f-muted f-small" style="margin:0 0 12px" x-show="!evidenceOptional">
            {{ $needsPhoto ? __('field.leave_needs_note_photo') : __('field.leave_needs_note') }}
        </p>
        <p class="f-muted f-small" style="margin:0 0 12px" x-show="mistake" x-cloak>
            {{ __('field.leave_mistake_hint') }}
        </p>
        <p class="f-muted f-small" style="margin:0 0 12px" x-show="shopClosed" x-cloak>
            {{ __('field.leave_closed_hint') }}
        </p>

        @if ($visit->order_id)
            <input type="hidden" name="outcome" value="order_taken">
            <div class="f-chip green" style="margin-bottom:12px">{{ __('field.order_already_on_visit') }}</div>
        @else
            <div class="f-choices">
                <label class="f-choice"><input type="radio" name="outcome" value="no_order" x-model="outcome"><span><i data-lucide="x-circle"></i>{{ __('field.no_order_today') }}</span></label>
                <label class="f-choice"><input type="radio" name="outcome" value="closed" x-model="outcome"><span><i data-lucide="lock"></i>{{ __('field.shop_closed') }}</span></label>
                <label class="f-choice"><input type="radio" name="outcome" value="follow_up" x-model="outcome"><span><i data-lucide="calendar-clock"></i>{{ __('field.follow_up_later') }}</span></label>
                <label class="f-choice"><input type="radio" name="outcome" value="cancelled" x-model="outcome"><span><i data-lucide="undo-2"></i>{{ __('field.opened_by_mistake') }}</span></label>
            </div>
        @endif

        <template x-if="!mistake">
            <div>
                <template x-if="!shopClosed">
                    <div>
                        <label class="f-label" for="leave-notes">{{ __('field.talk_notes_label') }} <em class="as-req">*</em></label>
                        <textarea id="leave-notes" class="f-textarea @error('notes') is-invalid @enderror" name="notes" rows="3" maxlength="1000" required placeholder="{{ __('field.talk_notes_placeholder') }}">{{ old('notes', $visit->notes) }}</textarea>
                        @error('notes')<div class="f-error">{{ $message }}</div>@enderror

                        <x-live-camera-photo
                            name="photo"
                            :required="$needsPhoto"
                            :title="$needsPhoto ? __('field.live_photo_required') : __('field.live_photo_optional')"
                            :hint="$needsPhoto ? __('field.live_photo_required_hint') : __('field.live_photo_optional_hint')"
                            class="f-cam-block"
                        />
                        @error('photo')<div class="f-error">{{ $message }}</div>@enderror
                        @error('photo_captured')<div class="f-error">{{ $message }}</div>@enderror
                    </div>
                </template>

                <template x-if="shopClosed">
                    <div>
                        <label class="f-label" for="leave-notes-closed">{{ __('field.talk_notes_optional_label') }}</label>
                        <textarea id="leave-notes-closed" class="f-textarea @error('notes') is-invalid @enderror" name="notes" rows="2" maxlength="1000" placeholder="{{ __('field.closed_notes_placeholder') }}">{{ old('notes', $visit->notes) }}</textarea>
                        @error('notes')<div class="f-error">{{ $message }}</div>@enderror

                        <x-live-camera-photo
                            name="photo"
                            :required="false"
                            :title="__('field.live_photo_optional')"
                            :hint="__('field.live_photo_optional_hint')"
                            class="f-cam-block"
                        />
                        @error('photo')<div class="f-error">{{ $message }}</div>@enderror
                        @error('photo_captured')<div class="f-error">{{ $message }}</div>@enderror
                    </div>
                </template>
            </div>
        </template>

        <button class="f-btn f-btn-primary f-btn-lg f-btn-block" style="margin-top:14px" type="submit">
            <i data-lucide="log-out"></i>
            <span x-text="mistake ? @js(__('field.cancel_visit')) : @js(__('field.save_visit_leave'))"></span>
        </button>
        <button class="f-btn f-btn-ghost f-btn-block" style="margin-top:8px" type="button" @click="open = false">{{ __('field.stay_at_shop') }}</button>
    </form>
</div>
