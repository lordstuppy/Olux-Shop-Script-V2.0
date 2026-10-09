<?php

namespace App\Providers;

use App\Models\User;
use App\Services\CartService;
use App\Services\Shkeeper\ShkeeperClient;
use App\Support\RequestId;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(RequestId::class);

        $this->app->bind(ShkeeperClient::class, fn ($app) => new ShkeeperClient(
            $app->make(HttpFactory::class),
            (string) config('services.shkeeper.base_url'),
            config('services.shkeeper.api_key'),
            config('services.shkeeper.webhook_secret'),
            (int) config('services.shkeeper.webhook_tolerance'),
            (int) config('services.shkeeper.timeout'),
        ));
    }

    public function boot(): void
    {
        $this->configureRateLimits();

        Gate::define('admin', fn (User $user) => $user->isAdmin());

        Paginator::defaultView('pagination');
        Paginator::defaultSimpleView('pagination');

        // Cart badge and currency for the layout. Error pages may render
        // without a session, so fall back to empty values.
        View::composer('layouts.app', function ($view) {
            $request = request();
            if ($request->hasSession()) {
                $cart = app(CartService::class);
                $view->with(['cartCount' => $cart->count(), 'shopCurrency' => $cart->currency()]);
            } else {
                $view->with(['cartCount' => 0, 'shopCurrency' => config('shop.default_currency')]);
            }
        });
    }

    private function configureRateLimits(): void
    {
        // Renders the 429 page with a specific explanation (works without JavaScript).
        $throttled = fn (string $message) => fn (Request $request, array $headers) => response()
            ->view('errors.429', ['reason' => $message], 429, $headers);

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by('login:'.Str::lower((string) $request->input('email')).'|'.$request->ip())
                ->response($throttled('Too many login attempts for this email. Wait one minute and try again.')),
            Limit::perMinute(20)->by('login-ip:'.$request->ip())
                ->response($throttled('Too many login attempts from your network. Wait one minute and try again.')),
        ]);

        RateLimiter::for('register', fn (Request $request) => Limit::perHour(10)->by('register:'.$request->ip())
            ->response($throttled('Too many accounts were created from your network. Try again in an hour.')));

        RateLimiter::for('password-reset', fn (Request $request) => Limit::perMinute(3)->by('reset:'.$request->ip())
            ->response($throttled('Too many password reset requests. Wait one minute and try again.')));

        RateLimiter::for('checkout', fn (Request $request) => Limit::perMinute(10)->by('checkout:'.($request->user()?->id ?? $request->ip()))
            ->response($throttled('Too many checkout attempts. Wait one minute before trying again.')));

        RateLimiter::for('redeem', fn (Request $request) => Limit::perMinute(5)->by('redeem:'.($request->user()?->id ?? $request->ip()))
            ->response($throttled('Too many gift card attempts. Wait one minute and try again.')));

        RateLimiter::for('forms', fn (Request $request) => Limit::perMinute(30)->by('forms:'.($request->user()?->id ?? $request->ip()))
            ->response($throttled('You are submitting forms too quickly. Wait a moment and try again.')));

        RateLimiter::for('webhook', fn (Request $request) => Limit::perMinute(120)->by('webhook:'.$request->ip()));

        RateLimiter::for('search', fn (Request $request) => Limit::perMinute(60)->by('search:'.$request->ip()));
    }
}
