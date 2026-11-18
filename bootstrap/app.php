<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);
        $middleware->web(prepend: [\App\Http\Middleware\AlignRequestOrigin::class]);
        $middleware->web(append: [
            \App\Http\Middleware\SetLocale::class,
            \Illuminate\Session\Middleware\AuthenticateSession::class,
        ]);
        $middleware->redirectGuestsTo(fn (\Illuminate\Http\Request $request) => match (true) {
            $request->is('field', 'field/*') => route('field.login'),
            $request->is('portal', 'portal/*') => route('portal.login'),
            default => route('login'),
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Illuminate\Session\TokenMismatchException $e, $request) {
            if ($request->expectsJson() || $request->wantsJson()) {
                return response()->json(['message' => 'Page expired. Please refresh and try again.'], 419);
            }

            $fallback = match (true) {
                $request->is('field', 'field/*') => route('field.login'),
                $request->is('portal', 'portal/*') => route('portal.login'),
                default => route('login'),
            };

            $referer = (string) $request->headers->get('referer', '');
            $target = $referer !== '' && str_starts_with($referer, $request->getSchemeAndHttpHost())
                ? $referer
                : $fallback;

            return redirect()
                ->to($target)
                ->withInput($request->except(['password', 'password_confirmation', '_token']))
                ->with('error', 'Your session was refreshed. Please try again.');
        });
    })->create();
