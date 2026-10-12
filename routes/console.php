<?php

use App\Enums\PayoutStatus;
use App\Enums\ProductStatus;
use App\Enums\SellerProfileStatus;
use App\Enums\UserRole;
use App\Enums\WebhookEventStatus;
use App\Jobs\DeliverOrder;
use App\Jobs\GenerateInvoicePdf;
use App\Jobs\ProcessWebhookEvent;
use App\Jobs\QueueHeartbeat;
use App\Mail\SubscriptionRenewalMail;
use App\Models\AuditLog;
use App\Models\OrderItem;
use App\Models\Payout;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\WebhookEvent;
use App\Services\AuditLogger;
use App\Services\CatalogService;
use App\Services\DisputeService;
use App\Services\Notifier;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\PayoutService;
use App\Services\ShkeeperPayoutService;
use App\Services\StaffAlerts;
use App\Services\SystemHealthService;
use App\Support\TranslationCatalog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
    app(StaffAlerts::class)->send(__('Seller ledger does not match orders and payouts'), [
        __(':count mismatches found by the nightly reconciliation.', ['count' => count($mismatches)]),
        __('Payouts may be wrong until this is explained. Details are on the reconciliation page and in the log.'),
    ], route('admin.reconciliation'), 'payout-reconciliation', 60 * 20);

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
                app(Notifier::class)->send($item->order->buyer, new SubscriptionRenewalMail($item));
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

Artisan::command('shop:recover-stuck-orders {--minutes=3}', function () {
    // The delivery and invoice jobs are queued after the payment commits. If the
    // database or the process dies in between, the order stays paid with nothing
    // queued. Re-queue them: both jobs are idempotent. Each order at most every 15 minutes.
    $since = now()->subMinutes((int) $this->option('minutes'));
    $undelivered = DB::table('orders')->join('order_items', 'order_items.order_id', '=', 'orders.id')
        ->join('products', 'products.id', '=', 'order_items.product_id')
        ->where('orders.status', 'paid')->where('orders.paid_at', '<', $since)->where('orders.paid_at', '>', now()->subDays(7))
        ->whereNull('order_items.delivered_at')->where('products.delivery_type', 'instant')
        // Lines whose delivery ran and failed (e.g. out of licence keys) wait for staff (admin redeliver).
        ->whereNotExists(fn ($q) => $q->from('audit_log')->where('audit_log.action', 'delivery.failed')->where('audit_log.target_type', 'OrderItem')
            ->whereRaw('audit_log.target_id = order_items.id::text'))
        ->distinct()->limit(200)->pluck('orders.id');
    $uninvoiced = DB::table('orders')->whereIn('status', ['paid', 'delivered', 'partially_refunded'])
        ->where('paid_at', '<', $since)->where('paid_at', '>', now()->subDays(7))
        ->whereNotExists(fn ($q) => $q->from('invoices')->whereColumn('invoices.order_id', 'orders.id'))
        ->limit(200)->pluck('id');
    $queued = 0;
    foreach ($undelivered as $id) {
        if (Cache::add("recover-delivery:{$id}", true, 900)) {
            DeliverOrder::dispatch((int) $id);
            $queued++;
        }
    }
    foreach ($uninvoiced as $id) {
        if (Cache::add("recover-invoice:{$id}", true, 900)) {
            GenerateInvoicePdf::dispatch((int) $id);
            $queued++;
        }
    }
    if ($queued > 0) {
        Log::warning('Re-queued {count} delivery or invoice jobs for paid orders that had none running', ['count' => $queued]);
    }
    $this->info("Re-queued {$queued} jobs.");
})->purpose('Re-queue delivery and invoice jobs of paid orders whose jobs were lost (crash between commit and queueing)');

Artisan::command('shop:staff-digest', function (StaffAlerts $alerts) {
    $until = now();
    $since = Carbon::createFromTimestamp((int) Cache::get('staff-digest:since', $until->copy()->subMinutes(15)->timestamp));
    $window = fn ($query, string $column = 'created_at') => $query->where($column, '>', $since)->where($column, '<=', $until);
    $counts = [
        __('seller applications') => $window(SellerProfile::query()->where('status', SellerProfileStatus::Pending->value), 'updated_at')->count(),
        __('products submitted for review') => $window(Product::query()->where('status', ProductStatus::PendingReview->value), 'updated_at')->count(),
        __('payout requests') => $window(Payout::query()->where('status', PayoutStatus::Requested->value))->count(),
        __('new support tickets') => $window(Ticket::query())->count(),
        __('customer replies on tickets') => $window(TicketMessage::query()->whereHas('ticket', fn ($q) => $q->whereColumn('tickets.user_id', 'ticket_messages.author_id')
            ->where('tickets.created_at', '<=', $since)), 'ticket_messages.created_at')->count(),
        __('accounts locked after failed sign-ins') => $window(AuditLog::query()->where('action', 'user.login_locked'))->count(),
    ];
    Cache::forever('staff-digest:since', $until->timestamp);

    $lines = [];
    foreach (array_filter($counts) as $what => $count) {
        $lines[] = '- '.$count.' '.$what;
    }
    if ($lines === []) {
        return 0;
    }
    $alerts->send(__('Needs attention since :time UTC', ['time' => $since->format('Y-m-d H:i')]), $lines, route('admin.dashboard'));
    $this->info(implode("\n", $lines));

    return 0;
})->purpose('Email staff a summary of work that arrived since the last run');

Artisan::command('shop:health-alerts', function (SystemHealthService $health, StaffAlerts $alerts) {
    foreach ($health->checks() as $section => $rows) {
        foreach ($rows as $check) {
            if ($check['level'] !== 'critical') {
                continue;
            }
            // Sent directly, not queued: the problem may be the queue itself.
            $alerts->send(__('Health problem: :check', ['check' => $check['label']]), [
                __(':section / :check: :value', ['section' => $section, 'check' => $check['label'], 'value' => $check['value']]),
                $check['hint'],
            ], route('admin.health'), 'health:'.$check['label'], 60, direct: true);
        }
    }

    return 0;
})->purpose('Email staff about critical system health checks (at most hourly per check)');

Artisan::command('shop:prune-notifications', function () {
    $read = UserNotification::query()->whereNotNull('read_at')->where('created_at', '<', now()->subDays(90))->delete();
    $old = UserNotification::query()->where('created_at', '<', now()->subDays(365))->delete();
    $this->info("Deleted {$read} read notifications older than 90 days and {$old} older than a year.");
})->purpose('Delete read notifications after 90 days and all after a year');

Artisan::command('shop:refresh-sales-ranking', function (CatalogService $catalog) {
    $this->info('Updated the units sold of '.$catalog->refreshSalesRanking().' products.');
})->purpose('Recompute units sold per product for the "best selling" sort');

Artisan::command('shop:prune-cache', function () {
    // The database cache store deletes an expired entry only when that key is
    // read again, so per-address rate-limit counters of one-time visitors
    // would stay forever. Other stores (Redis) expire entries themselves.
    $store = config('cache.stores.'.config('cache.default'));
    if (($store['driver'] ?? null) !== 'database') {
        $this->info('The cache store is not the database; nothing to prune.');

        return 0;
    }
    $table = DB::connection($store['connection'] ?? null)->table($store['table'] ?? 'cache');
    $deleted = 0;
    do {
        // Batches keep each delete short (no long lock on a busy table).
        $keys = (clone $table)->where('expiration', '<=', now()->getTimestamp())->limit(5000)->pluck('key');
        $deleted += $keys->isEmpty() ? 0 : (clone $table)->whereIn('key', $keys->all())->where('expiration', '<=', now()->getTimestamp())->delete();
    } while ($keys->count() === 5000);
    $this->info("Deleted {$deleted} expired cache entries.");

    return 0;
})->purpose('Delete expired entries from the database cache table');

Artisan::command('shop:prune-saved-carts', function () {
    $count = DB::table('saved_carts')->where('updated_at', '<', now()->subDays((int) config('shop.saved_cart_days')))->delete();
    $this->info("Deleted {$count} saved carts.");
})->purpose('Delete saved carts not changed for shop.saved_cart_days days (default 30)');

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
Schedule::command('shop:recover-stuck-orders')->everyMinute()->withoutOverlapping();
// HTTP-bound polls run in the background so a slow Shkeeper cannot delay the
// other tasks of the same minute (order expiry, webhook retries).
Schedule::command('shop:reconcile-payments')->everyFiveMinutes()->withoutOverlapping()->runInBackground();
Schedule::command('shop:reconcile-shkeeper-transfers')->everyFiveMinutes()->withoutOverlapping()->runInBackground();
Schedule::command('shop:retry-webhooks')->everyMinute()->withoutOverlapping();
Schedule::command('shop:reconcile-payouts')->dailyAt('03:15');
Schedule::command('queue:prune-failed --hours=720')->daily();
Schedule::command('shop:subscription-reminders')->hourly()->withoutOverlapping();
Schedule::command('shop:escalate-disputes')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('auth:clear-resets')->hourly();
Schedule::command('shop:prune-gateway-logs')->dailyAt('04:10');
Schedule::command('shop:prune-saved-carts')->dailyAt('04:20');
Schedule::command('shop:refresh-sales-ranking')->everyTenMinutes()->withoutOverlapping();
Schedule::command('shop:prune-cache')->hourly()->withoutOverlapping();
Schedule::command('shop:prune-notifications')->dailyAt('04:30');
Schedule::command('shop:staff-digest')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('shop:health-alerts')->everyFiveMinutes()->withoutOverlapping();
// Heartbeats for the system health page.
Schedule::call(fn () => Cache::put(SystemHealthService::SCHEDULER_HEARTBEAT, now()->timestamp, 86400))->everyMinute()->name('health:scheduler-heartbeat');
Schedule::job(new QueueHeartbeat)->everyFiveMinutes()->name('health:queue-heartbeat');
