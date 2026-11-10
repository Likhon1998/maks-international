<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Concerns\RedirectsWrongPortal;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class ShopAuthController extends Controller
{
    use RedirectsWrongPortal;

    public function __construct(private AuditLogger $auditLogger) {}

    public function showLogin()
    {
        if (Auth::check() && Auth::user()->portal === User::PORTAL_SHOP) {
            return redirect()->route('portal.dashboard');
        }

        return view('portal.auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        /** @var User $user */
        $user = Auth::user();

        if (! $user->is_active) {
            Auth::logout();
            throw ValidationException::withMessages([
                'email' => 'This account has been deactivated. Contact '.app(\App\Support\SiteContent::class)->siteName().' admin.',
            ]);
        }

        if ($user->portal !== User::PORTAL_SHOP) {
            return $this->wrongPortal($user, 'B2B Partner portal');
        }

        $shop = $user->primaryShop();

        if (! $shop || $shop->status !== Shop::STATUS_ACTIVE) {
            Auth::logout();
            throw ValidationException::withMessages([
                'email' => 'Your shop is not active yet. Contact '.app(\App\Support\SiteContent::class)->siteName().' admin.',
            ]);
        }

        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        $this->auditLogger->log('auth', 'shop_login', "Shop user {$user->email} signed in", $user, null, null, $user);

        return redirect()->intended(route('portal.dashboard'));
    }

    public function logout(Request $request)
    {
        $user = Auth::user();
        if ($user) {
            $this->auditLogger->log('auth', 'shop_logout', "Shop user {$user->email} signed out", $user, null, null, $user);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('portal.login');
    }
}
