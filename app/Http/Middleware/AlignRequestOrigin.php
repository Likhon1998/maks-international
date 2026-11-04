<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class AlignRequestOrigin
{
    public function handle(Request $request, Closure $next): Response
    {
        URL::forceRootUrl($request->getSchemeAndHttpHost());

        if ($request->isSecure()) {
            URL::forceScheme('https');
        }

        $domain = config('session.domain');
        if (! is_string($domain) || $domain === '' || strcasecmp($domain, 'null') === 0) {
            config(['session.domain' => null]);
        }

        // Always match the live request. Forcing Secure cookies while SSL is
        // broken/pending makes browsers drop the session on HTTP and triggers 419s.
        config(['session.secure' => $request->isSecure()]);

        $sessionDir = storage_path('framework/sessions');
        if (! is_dir($sessionDir)) {
            @mkdir($sessionDir, 0755, true);
        }

        return $next($request);
    }
}
