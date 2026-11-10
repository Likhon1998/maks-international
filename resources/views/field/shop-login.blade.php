@extends('field.layouts.app')
@php
    $checkLabels = [
        'account' => 'Owner account is active',
        'password' => 'Password works',
        'shop' => 'Login is linked to '.$shop->name,
        'active' => 'Shop is approved for ordering',
    ];
    $ownerUser = $shop->users->first();
@endphp
@section('title', $hasLogin ? 'Owner login' : 'Create owner login')
@section('heading', $hasLogin ? 'Owner login' : 'Create owner login')
@section('subheading', $shop->name.' · '.$shop->code)
@section('back', route('field.shops'))
@section('has-bar', '1')
@section('content')
    <form id="login-form" method="post" action="{{ route('field.shops.login.store', $shop) }}" class="f-split as-form"
          x-data="shopLogin(@js(old('login_email', $ownerUser?->email ?: $shop->email)), @js(! $result || $errors->any()))" @submit="saving = true">
        @csrf
        <div class="as-main">
            @if ($result)
                <section class="f-panel pl-result {{ $result['ok'] ? 'is-ok' : 'is-bad' }}">
                    <div class="pl-result-head">
                        <span class="pl-result-ico"><i data-lucide="{{ $result['ok'] ? 'shield-check' : 'shield-alert' }}"></i></span>
                        <div>
                            <strong>{{ $result['ok'] ? 'Login ready — give these to the owner' : 'Saved, but a check failed' }}</strong>
                            <span>{{ $result['ok'] ? 'Shown only once. Ask them to sign in on their phone now.' : 'Call the office before you leave.' }}</span>
                        </div>
                    </div>
                    <ul class="pl-checks">
                        @foreach ($checkLabels as $key => $label)
                            <li class="{{ ($result['checks'][$key] ?? false) ? 'ok' : 'bad' }}"><i data-lucide="{{ ($result['checks'][$key] ?? false) ? 'check' : 'x' }}"></i>{{ $label }}</li>
                        @endforeach
                    </ul>
                    <div class="pl-cred">
                        @foreach (['Login email' => $result['email'], 'Password' => $result['password'], 'Sign in at' => route('portal.login')] as $label => $value)
                            <div class="pl-cred-row">
                                <span>{{ $label }}</span>
                                <b>{{ $value }}</b>
                                <button type="button" class="pl-copy" @click="copy(@js($value), @js($label))" :aria-label="'Copy ' + @js($label)"><i data-lucide="copy"></i></button>
                            </div>
                        @endforeach
                    </div>
                    <div class="pl-result-actions">
                        @if ($result['chat_url'])
                            <a class="f-btn f-btn-success" href="{{ $result['chat_url'] }}" target="_blank" rel="noopener"><i data-lucide="message-circle"></i> Send on WhatsApp</a>
                        @endif
                        <button type="button" class="f-btn f-btn-ghost" @click="copy(@js($site->siteName().' shop login'."\nEmail: {$result['email']}\nPassword: {$result['password']}\nSign in: ".route('portal.login')), 'Login details')"><i data-lucide="clipboard-copy"></i> Copy all</button>
                        <button type="button" class="f-btn f-btn-ghost" x-show="!editing" @click="editing = true; $nextTick(() => $refs.password.focus())"><i data-lucide="key-round"></i> Change password</button>
                        <a class="f-btn f-btn-primary" href="{{ route('field.orders.create') }}"><i data-lucide="shopping-bag"></i> Take order</a>
                    </div>
                </section>
            @endif

            <section class="f-panel as-sec" x-show="editing" @if ($result && ! $errors->any()) x-cloak @endif>
                <div class="as-sec-head">
                    <span class="f-ico tone-violet"><i data-lucide="key-round"></i></span>
                    <div>
                        <h2>{{ $hasLogin ? 'Reset owner password' : 'Set owner login' }}</h2>
                        <p>{{ $hasLogin ? 'Current: '.($ownerUser?->email ?: '—') : 'Fill this in with the owner' }}</p>
                    </div>
                </div>
                <div class="as-sec-body pl-form">
                    <label class="as-field">
                        <span class="as-label">Login email <em class="as-req">*</em></span>
                        <span class="as-prefix">
                            <i data-lucide="mail"></i>
                            <input class="f-input @error('login_email') is-invalid @enderror" name="login_email" type="email" x-model="email" maxlength="180" placeholder="owner@email.com" autocomplete="off" required>
                        </span>
                        @error('login_email')<span class="f-error">{{ $message }}</span>@enderror
                    </label>
                    <label class="as-field">
                        <span class="as-label">Password <em class="as-req">*</em></span>
                        <span class="as-prefix">
                            <i data-lucide="lock"></i>
                            <input class="f-input @error('login_password') is-invalid @enderror" name="login_password" type="text" x-ref="password" value="{{ old('login_password', $suggestedPassword) }}" minlength="8" maxlength="64" autocomplete="off" required>
                        </span>
                        @error('login_password')<span class="f-error">{{ $message }}</span>@enderror
                    </label>
                    <button class="f-btn f-btn-primary f-btn-block" type="submit" :disabled="saving" style="margin-top:12px">
                        <i data-lucide="check"></i> <span x-text="saving ? 'Saving…' : ({{ $hasLogin ? 'true' : 'false' }} ? 'Update login' : 'Create login')"></span>
                    </button>
                </div>
            </section>
        </div>
        <aside class="as-side">
            <section class="f-panel as-preview">
                <div class="as-preview-top">
                    <span class="as-av">{{ strtoupper(mb_substr($shop->name, 0, 1)) }}</span>
                    <div style="min-width:0">
                        <b>{{ $shop->name }}</b>
                        <span>{{ $shop->owner_name ?: 'No owner name' }}</span>
                    </div>
                </div>
                <ul class="as-facts">
                    <li class="{{ $shop->phone ? 'ok' : '' }}"><i data-lucide="phone"></i><span>{{ $shop->phone ?: 'No phone' }}</span></li>
                    <li class="{{ $shop->city ? 'ok' : '' }}"><i data-lucide="map-pin"></i><span>{{ collect([$shop->address, $shop->city])->filter()->implode(', ') ?: 'No address' }}</span></li>
                    <li class="{{ $hasLogin ? 'ok' : '' }}"><i data-lucide="key-round"></i><span>{{ $hasLogin ? 'Has portal login' : 'No login yet' }}</span></li>
                </ul>
                <a class="f-btn f-btn-ghost f-btn-block" href="{{ route('field.shops') }}"><i data-lucide="arrow-left"></i> Back to shops</a>
            </section>
        </aside>
    </form>
@endsection

@push('scripts')
<script>
function shopLogin(email, editing) {
    return {
        email, editing, saving: false,
        copy(text, label) {
            navigator.clipboard?.writeText(String(text)).then(() => {
                window.dispatchEvent(new CustomEvent('toast', { detail: (label || 'Copied') + ' copied' }));
            });
        },
    };
}
</script>
@endpush
