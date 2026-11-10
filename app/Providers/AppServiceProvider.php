<?php

namespace App\Providers;

use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\ShopVisit;
use App\Models\User;
use App\Policies\OrderPolicy;
use App\Policies\ProductPolicy;
use App\Policies\ShopPolicy;
use App\Policies\ShopVisitPolicy;
use App\Support\SiteContent;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SiteContent::class);
    }

    public function boot(): void
    {
        Paginator::defaultView('vendor.pagination.admin');
        Paginator::defaultSimpleView('vendor.pagination.simple-admin');
        $this->ensureShopGeoColumns();
        $this->ensurePhotoEvidenceColumns();

        Gate::policy(Shop::class, ShopPolicy::class);
        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(ShopVisit::class, ShopVisitPolicy::class);
        Gate::policy(Order::class, OrderPolicy::class);

        RedirectIfAuthenticated::redirectUsing(function ($request) {
            $user = $request->user();

            if ($user?->portal === User::PORTAL_SHOP) {
                return route('portal.dashboard');
            }

            if ($user?->portal === User::PORTAL_SALESMAN) {
                return route('field.dashboard');
            }

            return route('dashboard');
        });

        Gate::before(function ($user, string $ability) {
            if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
                return true;
            }

            return null;
        });

        View::composer('*', function ($view) {
            if (! $view->offsetExists('site')) {
                $view->with('site', app(SiteContent::class));
            }
        });

        View::composer('field.layouts.app', function ($view) {
            $user = auth()->user();
            $view->with([
                'fieldProfile' => $user?->salesmanProfile,
                'fieldOpenVisit' => $user
                    ? ShopVisit::query()->with('shop')->where('salesman_id', $user->id)->whereNull('checked_out_at')->first()
                    : null,
            ]);
        });

        RateLimiter::for('login', function (Request $request) {
            $tooMany = fn () => back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'Too many login attempts. Please wait a minute and try again.']);

            return [
                Limit::perMinute(5)->by(Str::lower((string) $request->input('email')).'|'.$request->ip())->response($tooMany),
                Limit::perMinute(20)->by($request->ip())->response($tooMany),
            ];
        });
    }

    private function ensureShopGeoColumns(): void
    {
        if (! Schema::hasTable('shops')) {
            return;
        }

        $add = [];
        foreach (['division' => 80, 'district' => 80, 'thana' => 80, 'post_office' => 120, 'location' => 160] as $column => $length) {
            if (! Schema::hasColumn('shops', $column)) {
                $add[$column] = $length;
            }
        }
        if ($add === []) {
            return;
        }

        Schema::table('shops', function (Blueprint $table) use ($add) {
            foreach ($add as $column => $length) {
                $table->string($column, $length)->nullable();
            }
        });
    }

    private function ensurePhotoEvidenceColumns(): void
    {
        if (Schema::hasTable('shop_visits') && ! Schema::hasColumn('shop_visits', 'photo_path')) {
            Schema::table('shop_visits', function (Blueprint $table) {
                $table->string('photo_path', 255)->nullable();
            });
        }

        if (Schema::hasTable('shops') && ! Schema::hasColumn('shops', 'photo_path')) {
            Schema::table('shops', function (Blueprint $table) {
                $table->string('photo_path', 255)->nullable();
            });
        }
    }
}
