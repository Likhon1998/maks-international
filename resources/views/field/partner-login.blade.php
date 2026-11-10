@extends('field.layouts.app')
@php
    $shop = $inquiry->shop;
    $hasLogin = (bool) $inquiry->portal_email;
    $checkLabels = [
        'account' => 'Partner account is active',
        'password' => 'Password works',
        'shop' => 'Login is linked to '.$shop->name,
        'active' => 'Shop is approved for ordering',
    ];
@endphp
@section('title', $hasLogin ? 'Partner login' : 'Create partner login')
@section('heading', $hasLogin ? 'Partner login' : 'Create partner login')
@section('subheading', $shop->name.' · '.$shop->code)
@section('back', route('field.partners'))
@section('has-bar', '1')
@section('content')
    <form id="login-form" method="post" action="{{ route('field.partners.login.store', $inquiry) }}" class="f-split as-form"
          x-data="partnerLogin(@js(old('email', $inquiry->portal_email ?: $inquiry->email)), @js(! $result || $errors->any()))" @submit="saving = true">
        @csrf
        <div class="as-main">
            @if ($result)
                <section class="f-panel pl-result {{ $result['ok'] ? 'is-ok' : 'is-bad' }}">
                    <div class="pl-result-head">
                        <span class="pl-result-ico"><i data-lucide="{{ $result['ok'] ? 'shield-check' : 'shield-alert' }}"></i></span>
                        <div>
                            <strong>{{ $result['ok'] ? 'Login works — the owner can sign in now' : 'The login was saved but a check failed' }}</strong>
                            <span>{{ $result['ok'] ? 'Give these details to the owner. They are shown only once.' : 'Call the office before you leave the shop.' }}</span>
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
                        <button type="button" class="f-btn f-btn-ghost" @click="copy(@js($site->siteName().' partner login'."\nEmail: {$result['email']}\nPassword: {$result['password']}\nSign in: ".route('portal.login')), 'Login details')"><i data-lucide="clipboard-copy"></i> Copy all</button>
                        <button type="button" class="f-btn f-btn-ghost" x-show="!editing" @click="editing = true; $nextTick(() => $refs.password.focus())"><i data-lucide="key-round"></i> Change password</button>
                    </div>
                    <p class="pl-tip"><i data-lucide="smartphone"></i> Ask the owner to open the link on <b>their own phone</b> and sign in while you are there.</p>
                </section>
            @endif

            <section class="f-panel as-sec" x-show="editing" @if ($result && ! $errors->any()) x-cloak @endif>
                <div class="as-sec-head">
                    <span class="f-ico tone-teal"><i data-lucide="store"></i></span>
                    <div><h2>{{ $shop->name }}</h2><p>Accepted by the office {{ $inquiry->reviewed_at?->diffForHumans() }}</p></div>
                </div>
                <div class="as-sec-body">
                    <div class="pl-facts">
                        <span><i data-lucide="user"></i>{{ $shop->owner_name ?: $inquiry->contact_name ?: 'No owner name' }}</span>
                        <span><i data-lucide="phone"></i>{{ $inquiry->phone ?: $shop->phone ?: '—' }}</span>
                        <span><i data-lucide="map-pin"></i>{{ $shop->city ?: $inquiry->city ?: '—' }}</span>
                        <span><i data-lucide="badge-check"></i>{{ $shop->statusLabel() }}</span>
                    </div>
                </div>
            </section>

            <section class="f-panel as-sec" x-show="editing" @if ($result && ! $errors->any()) x-cloak @endif>
                <div class="as-sec-head">
                    <span class="f-ico tone-violet"><i data-lucide="key-round"></i></span>
                    <div><h2>{{ $hasLogin ? 'Reset the password' : 'Set the login' }}</h2><p>{{ $hasLogin ? 'Current login: '.$inquiry->portal_email : 'Fill this in together with the owner' }}</p></div>
                </div>
                <div class="as-sec-body pl-form">
                    <label class="as-field">
                        <span class="as-label">Login email (user ID) <em class="as-req">*</em></span>
                        <span class="as-prefix">
                            <i data-lucide="mail"></i>
                            <input class="f-input @error('email') is-invalid @enderror" name="email" x-model="email" type="email" inputmode="email" autocapitalize="off" spellcheck="false" maxlength="180" required placeholder="owner@example.com">
                        </span>
                        @error('email')<span class="f-error">{{ $message }}</span>@enderror
                    </label>
                    <label class="as-field">
                        <span class="as-label">Password <em class="as-req">*</em></span>
                        <span class="pl-pass">
                            <span class="as-prefix">
                                <i data-lucide="lock"></i>
                                <input class="f-input @error('password') is-invalid @enderror" name="password" x-ref="password" x-model="password" :type="show ? 'text' : 'password'" autocomplete="new-password" autocapitalize="off" spellcheck="false" minlength="8" maxlength="64" required placeholder="At least 8 characters">
                            </span>
                            <button type="button" class="f-btn f-btn-ghost pl-eye" @click="show = !show" :aria-label="show ? 'Hide password' : 'Show password'">
                                <span x-show="!show"><i data-lucide="eye"></i></span><span x-show="show" x-cloak><i data-lucide="eye-off"></i></span>
                            </button>
                            <button type="button" class="f-btn f-btn-ghost" @click="generate()"><i data-lucide="sparkles"></i> Suggest</button>
                        </span>
                        @error('password')<span class="f-error">{{ $message }}</span>@enderror
                        <span class="pl-strength" :class="strength.tone"><i :style="'width:' + strength.pct + '%'"></i><em x-text="strength.label"></em></span>
                    </label>
                    <button class="f-btn f-btn-primary f-btn-lg f-btn-block f-desktop-only" type="submit" :disabled="saving">
                        <i data-lucide="shield-check"></i> <span x-text="saving ? 'Checking…' : @js($hasLogin ? 'Save new password & check' : 'Create login & check it works')"></span>
                    </button>
                </div>
            </section>
        </div>

        <aside class="as-side">
            <section class="f-panel as-preview">
                <div class="as-preview-top">
                    <div class="as-av" style="background:linear-gradient(135deg,#8B5CF6,#6D28D9)"><i data-lucide="key-round"></i></div>
                    <div style="min-width:0"><b>Make sure the login works</b><span>Do this at the shop</span></div>
                </div>
                <ol class="f-howto" style="margin-top:12px">
                    <li><b>Set it with the owner</b>Use the owner's own email and a password they will remember.</li>
                    <li><b>We check it right away</b>Account, password, shop link and approval are all checked.</li>
                    <li><b>Owner signs in</b>On their own phone at {{ parse_url(route('portal.login'), PHP_URL_PATH) }}.</li>
                    <li><b>Send the details</b>On WhatsApp, so they have them later.</li>
                </ol>
                <p class="as-foot"><i data-lucide="info"></i> Owner forgot the password? Come back here and reset it.</p>
            </section>
        </aside>

        <div class="f-cartbar as-bar" x-show="editing" @if ($result && ! $errors->any()) x-cloak @endif>
            <div class="f-cartbar-text">
                <small>{{ $shop->code }}</small>
                <strong>{{ $hasLogin ? 'Reset password' : 'Create login' }}</strong>
            </div>
            <button class="f-btn f-btn-success" type="submit" :disabled="saving">
                <i data-lucide="shield-check"></i> <span x-text="saving ? 'Checking…' : 'Save & check'"></span>
            </button>
        </div>
        <div class="pl-toast" x-show="toast" x-transition.opacity x-cloak x-text="toast"></div>
    </form>
@endsection

@push('scripts')
<script>
function partnerLogin(email, editing) {
    return {
        email,
        editing,
        password: '',
        show: true,
        saving: false,
        toast: '',
        get strength() {
            const p = this.password;
            if (!p) return { pct: 0, tone: '', label: '' };
            if (p.length < 8) return { pct: 25, tone: 'weak', label: 'Too short' };
            const kinds = [/[a-z]/, /[A-Z]/, /\d/, /[^A-Za-z0-9]/].filter((r) => r.test(p)).length;
            return kinds >= 3 ? { pct: 100, tone: 'good', label: 'Strong' } : { pct: 60, tone: 'ok', label: 'OK' };
        },
        generate() {
            const letters = 'ABCDEFGHJKMNPQRSTUVWXYZ';
            const pick = (s) => s[Math.floor(Math.random() * s.length)];
            this.password = 'Bt' + Array.from({ length: 4 }, () => pick(letters)).join('') + (10 + Math.floor(Math.random() * 90));
            this.show = true;
        },
        copy(text, label) {
            const done = () => { this.toast = label + ' copied'; setTimeout(() => (this.toast = ''), 1600); };
            if (navigator.clipboard) navigator.clipboard.writeText(text).then(done, done);
            else done();
        },
    };
}
</script>
@endpush
