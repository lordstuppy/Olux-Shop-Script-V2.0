<?php

use App\Enums\WebhookEventStatus;
use App\Jobs\ProcessWebhookEvent;
use App\Mail\SubscriptionRenewalMail;
use App\Models\OrderItem;
use App\Models\WebhookEvent;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\PayoutService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schedule;

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

Schedule::command('shop:expire-orders')->everyMinute()->withoutOverlapping();
Schedule::command('shop:reconcile-payments')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('shop:retry-webhooks')->everyMinute()->withoutOverlapping();
Schedule::command('shop:reconcile-payouts')->dailyAt('03:15');
Schedule::command('queue:prune-failed --hours=720')->daily();
Schedule::command('shop:subscription-reminders')->hourly()->withoutOverlapping();
Schedule::command('auth:clear-resets')->hourly();
