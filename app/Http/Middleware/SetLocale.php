<?php

namespace App\Http\Middleware;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public const LOCALES = ['en', 'bn'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = (string) session('locale', config('app.locale', 'en'));

        if (! in_array($locale, self::LOCALES, true)) {
            $locale = 'en';
        }

        app()->setLocale($locale);
        Carbon::setLocale($locale === 'bn' ? 'bn' : 'en');

        return $next($request);
    }
}
