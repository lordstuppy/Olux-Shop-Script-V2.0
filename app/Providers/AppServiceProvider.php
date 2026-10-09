<?php

namespace App\Providers;

use App\Models\User;
use App\Services\CartService;
use App\Services\Security\ClamAvScanner;
use App\Services\Security\VirusScanner;
use App\Services\Shkeeper\ShkeeperClient;
use App\Support\Permissions;
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

        $this->app->bind(VirusScanner::class, fn () => new ClamAvScanner(
            (string) config('shop.clamav.host'),
            (int) config('shop.clamav.port'),
            (int) config('shop.clamav.timeout'),
        ));

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

        foreach (Permissions::MAP as $ability => $roles) {
            Gate::define($ability, fn (User $user) => in_array($user->role->value, $roles, true));
        }

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
        $limits = config('shop.rate_limits');

        // Renders the 429 page with a specific explanation (works without JavaScript).
        $throttled = fn (string $message) => fn (Request $request, array $headers) => response()
            ->view('errors.429', ['reason' => $message], 429, $headers);

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute($limits['login_per_email'])->by('login:'.Str::lower((string) $request->input('email')).'|'.$request->ip())
                ->response($throttled('Too many login attempts for this email. Wait one minute and try again.')),
            Limit::perMinute($limits['login_per_ip'])->by('login-ip:'.$request->ip())
                ->response($throttled('Too many login attempts from your network. Wait one minute and try again.')),
        ]);

        RateLimiter::for('register', fn (Request $request) => Limit::perHour($limits['register_per_hour'])->by('register:'.$request->ip())
            ->response($throttled('Too many accounts were created from your network. Try again in an hour.')));

        RateLimiter::for('password-reset', fn (Request $request) => Limit::perMinute($limits['password_reset'])->by('reset:'.$request->ip())
            ->response($throttled('Too many password reset requests. Wait one minute and try again.')));

        RateLimiter::for('checkout', fn (Request $request) => Limit::perMinute($limits['checkout'])->by('checkout:'.($request->user()?->id ?? $request->ip()))
            ->response($throttled('Too many checkout attempts. Wait one minute before trying again.')));

        RateLimiter::for('redeem', fn (Request $request) => Limit::perMinute($limits['redeem'])->by('redeem:'.($request->user()?->id ?? $request->ip()))
            ->response($throttled('Too many gift card attempts. Wait one minute and try again.')));

        RateLimiter::for('forms', fn (Request $request) => Limit::perMinute($limits['forms'])->by('forms:'.($request->user()?->id ?? $request->ip()))
            ->response($throttled('You are submitting forms too quickly. Wait a moment and try again.')));

        RateLimiter::for('webhook', fn (Request $request) => Limit::perMinute($limits['webhook'])->by('webhook:'.$request->ip()));

        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute($limits['login_per_ip'])->by('2fa-ip:'.$request->ip())
            ->response($throttled('Too many verification attempts from your network. Wait one minute and try again.')));

        RateLimiter::for('verification', fn (Request $request) => Limit::perMinute(6)->by('verify:'.($request->user()?->id ?? $request->ip()))
            ->response($throttled('Too many confirmation emails requested. Wait one minute and try again.')));

        RateLimiter::for('search', fn (Request $request) => Limit::perMinute($limits['search'])->by('search:'.$request->ip()));
    }
}
