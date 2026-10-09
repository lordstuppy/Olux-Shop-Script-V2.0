<?php

use App\Exceptions\UserFacingException;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureStaffTwoFactor;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\PreventRequestForgery;
use App\Http\Middleware\RequireRecentPassword;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TrustProxies;
use App\Support\RequestId;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery as FrameworkPreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);
        $middleware->append(SecurityHeaders::class);

        $middleware->web(
            append: [EnsureUserIsActive::class],
            replace: [FrameworkPreventRequestForgery::class => PreventRequestForgery::class],
        );

        // Shkeeper callbacks are authenticated by their HMAC signature instead.
        $middleware->preventRequestForgery(except: ['webhooks/shkeeper', 'webhooks/shkeeper/payouts']);

        $middleware->alias([
            'role' => EnsureRole::class,
            'staff.2fa' => EnsureStaffTwoFactor::class,
            'password.recent' => RequireRecentPassword::class,
        ]);

        // Proxy IPs/CIDRs come from config (shop.trusted_proxies) so they work with config:cache.
        $middleware->replace(Illuminate\Http\Middleware\TrustProxies::class, TrustProxies::class);

        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('account.orders'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('webhooks/*', 'health', 'search/suggest') || $request->expectsJson(),
        );

        // Count unexpected errors for /metrics; the full trace goes to the JSON log.
        $exceptions->report(function (Throwable $e) {
            if (! $e instanceof HttpExceptionInterface) {
                try {
                    // The database store does not create missing keys on increment.
                    Cache::add('metrics:exceptions_total', 0);
                    Cache::increment('metrics:exceptions_total');
                } catch (Throwable) {
                    // Metrics must never mask the original error.
                }
            }
        });

        // Business rule failures carry a message written for the user. They are
        // expected (wrong code, sold out), so they are neither logged as errors
        // nor counted in shop_exceptions_total; services log notices where needed.
        $exceptions->dontReport(UserFacingException::class);
        $exceptions->render(function (UserFacingException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
            if ($request->isMethod('GET')) {
                return response()->view('errors.message', ['message' => $e->getMessage()], 422);
            }

            return back()->withInput($request->except(['password', 'password_confirmation', 'current_password', 'code']))
                ->with('error', $e->getMessage());
        });

        $exceptions->context(fn () => ['request_id' => app(RequestId::class)->get()]);

        $exceptions->dontFlash(['password', 'password_confirmation', 'current_password', 'code']);
    })->create();
