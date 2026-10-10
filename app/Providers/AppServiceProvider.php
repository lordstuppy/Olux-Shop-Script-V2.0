<?php

namespace App\Providers;

use App\Http\Controllers\Admin\AnnouncementController;
use App\Models\Announcement;
use App\Models\User;
use App\Services\CartService;
use App\Services\Security\ClamAvScanner;
use App\Services\Security\VirusScanner;
use App\Services\Shkeeper\ShkeeperClient;
use App\Services\SystemHealthService;
use App\Support\Permissions;
use App\Support\RequestId;
use App\Support\Settings;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
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
            config('services.shkeeper.payout_username'),
            config('services.shkeeper.payout_password'),
        ));
    }

    public function boot(): void
    {
        // Stored settings must never be baked into bootstrap/cache/config.php,
        // and long-running queue workers pick up changes before every job.
        if (! $this->app->runningConsoleCommand('config:cache', 'optimize')) {
            Settings::apply();
        }
        Queue::before(fn () => Settings::apply());
        // Heartbeat for the system health page (see App\Jobs\QueueHeartbeat).
        Queue::after(fn () => rescue(fn () => Cache::put(SystemHealthService::QUEUE_HEARTBEAT, now()->timestamp, 86400), report: false));
        $this->configureRateLimits();

        foreach (Permissions::MAP as $ability => $roles) {
            // Every staff ability also requires two-factor authentication, so pages
            // outside /admin (shared ticket, order and dispute pages) are covered too.
            Gate::define($ability, fn (User $user) => in_array($user->role->value, $roles, true) && $user->meetsStaffTwoFactorRule());
        }

        // Bring back the buyer's saved cart (see CartService) at every sign-in.
        Event::listen(Login::class, function (Login $event) {
            if ($event->guard === 'web' && $event->user instanceof User && request()->hasSession()) {
                app(CartService::class)->restoreFor($event->user);
            }
        });

        Paginator::defaultView('pagination');
        Paginator::defaultSimpleView('pagination');

        // Cart badge and currency for the layout. Error pages may render
        // without a session, so fall back to empty values.
        View::composer('layouts.app', function ($view) {
            $request = request();
            try {
                // Plain arrays only: the cache refuses to unserialize objects (serializable_classes).
                $announcements = Cache::remember(AnnouncementController::CACHE_KEY, 60, fn () => Announcement::query()->current()->latest('id')->limit(2)
                    ->get(['title', 'body'])->map(fn ($a) => ['title' => $a->title, 'body' => $a->body])->all());
            } catch (\Throwable) {
                $announcements = [];
            }
            $view->with('announcements', $announcements);

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
                ->response($throttled(__('Too many login attempts for this email. Wait one minute and try again.'))),
            Limit::perMinute($limits['login_per_ip'])->by('login-ip:'.$request->ip())
                ->response($throttled(__('Too many login attempts from your network. Wait one minute and try again.'))),
        ]);

        // Re-authentication of a signed-in user (password confirmation and
        // change). Keyed by account so people sharing an address do not
        // block each other; these forms carry no email field.
        RateLimiter::for('password-check', fn (Request $request) => Limit::perMinute($limits['login_per_email'])
            ->by('password-check:'.($request->user()?->getAuthIdentifier() ?? $request->ip()))
            ->response($throttled(__('Too many password attempts. Wait one minute and try again.'))));

        RateLimiter::for('register', fn (Request $request) => Limit::perHour($limits['register_per_hour'])->by('register:'.$request->ip())
            ->response($throttled(__('Too many accounts were created from your network. Try again in an hour.'))));

        RateLimiter::for('password-reset', fn (Request $request) => Limit::perMinute($limits['password_reset'])->by('reset:'.$request->ip())
            ->response($throttled(__('Too many password reset requests. Wait one minute and try again.'))));

        // Email change checks the password, so it is limited per account (not shared with password resets).
        RateLimiter::for('email-change', fn (Request $request) => Limit::perMinute($limits['email_change'])->by('email-change:'.($request->user()?->id ?? $request->ip()))
            ->response($throttled(__('Too many email change attempts. Wait one minute and try again.'))));

        RateLimiter::for('checkout', fn (Request $request) => Limit::perMinute($limits['checkout'])->by('checkout:'.($request->user()?->id ?? $request->ip()))
            ->response($throttled(__('Too many checkout attempts. Wait one minute before trying again.'))));

        RateLimiter::for('redeem', fn (Request $request) => Limit::perMinute($limits['redeem'])->by('redeem:'.($request->user()?->id ?? $request->ip()))
            ->response($throttled(__('Too many gift card attempts. Wait one minute and try again.'))));

        RateLimiter::for('forms', fn (Request $request) => Limit::perMinute($limits['forms'])->by('forms:'.($request->user()?->id ?? $request->ip()))
            ->response($throttled(__('You are submitting forms too quickly. Wait a moment and try again.'))));

        RateLimiter::for('webhook', fn (Request $request) => Limit::perMinute($limits['webhook'])->by('webhook:'.$request->ip()));

        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute($limits['login_per_ip'])->by('2fa-ip:'.$request->ip())
            ->response($throttled(__('Too many verification attempts from your network. Wait one minute and try again.'))));

        RateLimiter::for('verification', fn (Request $request) => Limit::perMinute(6)->by('verify:'.($request->user()?->id ?? $request->ip()))
            ->response($throttled(__('Too many confirmation emails requested. Wait one minute and try again.'))));

        RateLimiter::for('search', fn (Request $request) => Limit::perMinute($limits['search'])->by('search:'.$request->ip()));
    }
}
