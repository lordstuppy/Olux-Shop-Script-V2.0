<?php

use App\Enums\UserRole;
use App\Enums\WebhookEventStatus;
use App\Jobs\ProcessWebhookEvent;
use App\Jobs\QueueHeartbeat;
use App\Mail\SubscriptionRenewalMail;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\AuditLogger;
use App\Services\DisputeService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\PayoutService;
use App\Services\ShkeeperPayoutService;
use App\Services\SystemHealthService;
use App\Support\TranslationCatalog;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

Artisan::command('shop:expire-orders', function (OrderService $orders) {
    $count = $orders->expireStale();
    $this->info("Expired {$count} unpaid orders.");
})->purpose('Expire unpaid orders past their deadline and release reserved stock');

Artisan::command('shop:reconcile-payments', function (PaymentService $payments) {
    $count = $payments->reconcilePending();
    $this->info("Applied {$count} payment updates from Shkeeper status polling.");
})->purpose('Poll Shkeeper for pending invoices whose webhook may have been lost');

Artisan::command('shop:retry-webhooks', function () {
    $ids = WebhookEvent::query()->where('status', WebhookEventStatus::Failed->value)
        ->where('next_attempt_at', '<=', now())->orderBy('id')->limit(100)->pluck('id');
    foreach ($ids as $id) {
        ProcessWebhookEvent::dispatch($id);
    }
    $this->info("Queued {$ids->count()} webhook events for retry.");
})->purpose('Re-queue failed webhook events whose backoff has elapsed');

Artisan::command('shop:reconcile-payouts', function (PayoutService $payouts) {
    $mismatches = $payouts->reconcile();
    if ($mismatches === []) {
        $this->info('Seller ledger matches completed orders and payouts.');

        return 0;
    }
    $this->table(['seller_id', 'currency', 'check', 'expected', 'actual'], $mismatches);

    return 1;
})->purpose('Reconcile the seller payout ledger against completed orders');

Artisan::command('shop:subscription-reminders', function () {
    $days = (int) config('shop.renewal_reminder_days');
    $sent = 0;
    OrderItem::query()->with(['order.buyer', 'product'])
        ->whereNull('renewal_reminded_at')->whereNotNull('access_expires_at')
        ->whereBetween('access_expires_at', [now(), now()->addDays($days)])
        ->whereHas('order', fn ($q) => $q->whereIn('status', ['paid', 'delivered', 'partially_refunded']))
        ->chunkById(200, function ($items) use (&$sent) {
            foreach ($items as $item) {
                Mail::to($item->order->buyer)->queue(new SubscriptionRenewalMail($item));
                $item->forceFill(['renewal_reminded_at' => now()])->save();
                $sent++;
            }
        });
    $this->info("Queued {$sent} renewal reminders.");
})->purpose('Email buyers whose subscription access ends soon');

Artisan::command('shop:reconcile-shkeeper-transfers', function (ShkeeperPayoutService $transfers) {
    $count = $transfers->reconcile();
    $this->info("Updated {$count} payouts or refunds from Shkeeper status polling.");
})->purpose('Poll Shkeeper for payouts and crypto refunds whose callback has not arrived');

Artisan::command('shop:escalate-disputes', function (DisputeService $disputes) {
    $count = $disputes->escalateOverdue();
    $this->info("Escalated {$count} disputes whose seller did not respond in time.");
})->purpose('Hand disputes to staff when the seller missed the response deadline');

Artisan::command('shop:prune-gateway-logs {--days=90}', function () {
    $count = DB::table('gateway_logs')->where('created_at', '<', now()->subDays((int) $this->option('days')))->delete();
    $this->info("Deleted {$count} gateway log entries.");
})->purpose('Delete gateway log entries older than --days (default 90)');

Artisan::command('shop:create-admin {email} {--name=Administrator}', function (string $email) {
    $validator = Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255', 'unique:users,email']]);
    if ($validator->fails()) {
        $this->error($validator->errors()->first('email'));

        return 1;
    }
    $password = $this->secret('Password (at least 12 characters, letters and numbers)');
    $check = Validator::make(['password' => $password], ['password' => ['required', PasswordRule::min(12)->letters()->numbers()]]);
    if ($check->fails() || $password !== $this->secret('Repeat the password')) {
        $this->error($check->fails() ? $check->errors()->first('password') : 'The passwords do not match.');

        return 1;
    }

    $user = User::create(['name' => (string) $this->option('name'), 'email' => Str::lower($email), 'password_hash' => $password]);
    $user->forceFill(['role' => UserRole::Admin, 'email_verified_at' => now()])->save();
    app(AuditLogger::class)->log('user.admin_created_cli', $user, [], null);
    $this->info("Admin {$user->email} created. Sign in and set up two-factor authentication; /admin stays locked until you do.");

    return 0;
})->purpose('Create an administrator account (interactive password prompt)');

Artisan::command('shop:lang-extract {--check : Exit 1 if lang/en.json is out of date instead of writing it}', function () {
    $json = TranslationCatalog::encode(TranslationCatalog::extract());
    $current = is_file(TranslationCatalog::path()) ? file_get_contents(TranslationCatalog::path()) : '';
    if ($this->option('check')) {
        if ($json !== $current) {
            $this->error('lang/en.json is out of date. Run: php artisan shop:lang-extract');

            return 1;
        }
        $this->info('lang/en.json is up to date.');

        return 0;
    }
    file_put_contents(TranslationCatalog::path(), $json);
    $this->info('Wrote '.count(json_decode($json, true)).' keys to lang/en.json.');

    return 0;
})->purpose('Collect translation keys from the source into lang/en.json');

Schedule::command('shop:expire-orders')->everyMinute()->withoutOverlapping();
Schedule::command('shop:reconcile-payments')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('shop:reconcile-shkeeper-transfers')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('shop:retry-webhooks')->everyMinute()->withoutOverlapping();
Schedule::command('shop:reconcile-payouts')->dailyAt('03:15');
Schedule::command('queue:prune-failed --hours=720')->daily();
Schedule::command('shop:subscription-reminders')->hourly()->withoutOverlapping();
Schedule::command('shop:escalate-disputes')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('auth:clear-resets')->hourly();
Schedule::command('shop:prune-gateway-logs')->dailyAt('04:10');
// Heartbeats for the system health page.
Schedule::call(fn () => Cache::put(SystemHealthService::SCHEDULER_HEARTBEAT, now()->timestamp, 86400))->everyMinute()->name('health:scheduler-heartbeat');
Schedule::job(new QueueHeartbeat)->everyFiveMinutes()->name('health:queue-heartbeat');
