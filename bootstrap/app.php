<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\PreventRequestForgery;
use App\Http\Middleware\SecurityHeaders;
use App\Exceptions\UserFacingException;
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
        $middleware->preventRequestForgery(except: ['webhooks/shkeeper']);

        $middleware->alias(['role' => EnsureRole::class]);

        // Comma-separated proxy IPs/CIDRs (for example the nginx container network).
        $proxies = env('TRUSTED_PROXIES');
        if ($proxies) {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }

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
                    Cache::increment('metrics:exceptions_total');
                } catch (Throwable) {
                    // Metrics must never mask the original error.
                }
            }
        });

        // Business rule failures carry a message written for the user.
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
