<?php

namespace App\Jobs;

use App\Mail\OrderPaidMail;
use App\Models\Order;
use App\Services\DeliveryService;
use App\Services\Notifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers a freshly paid order and then emails the buyer. Delivery is
 * idempotent (delivered items are skipped), so retries are safe.
 */
class DeliverOrder implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @param  bool  $resend  staff asked to deliver again: the buyer gets the email again too */
    public function __construct(public int $orderId, public bool $resend = false) {}

    /** @return list<int> seconds between attempts */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(DeliveryService $delivery): void
    {
        $order = Order::query()->with('buyer')->findOrFail($this->orderId);
        $delivery->deliver($order);
        // Once per order: shop:recover-stuck-orders can queue a second job while the
        // queue lags, and the buyer must not get the email (and entry) twice. The
        // mark is taken atomically and given back if sending fails, so a retry sends it.
        $claimed = Order::query()->whereKey($order->id)->whereNull('paid_notice_sent_at')->update(['paid_notice_sent_at' => now()]) === 1;
        if (! $claimed && ! $this->resend) {
            return;
        }
        try {
            app(Notifier::class)->send($order->buyer, new OrderPaidMail($order->fresh()));
        } catch (Throwable $e) {
            if ($claimed) {
                Order::query()->whereKey($order->id)->update(['paid_notice_sent_at' => null]);
            }
            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error('Failed to deliver order {order_id} after all retries: {reason}', [
            'order_id' => $this->orderId,
            'reason' => $e->getMessage(),
            'exception' => $e,
        ]);
    }
}
