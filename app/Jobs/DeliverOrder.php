<?php

namespace App\Jobs;

use App\Mail\OrderPaidMail;
use App\Models\Order;
use App\Services\DeliveryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Delivers a freshly paid order and then emails the buyer. Delivery is
 * idempotent (delivered items are skipped), so retries are safe.
 */
class DeliverOrder implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public int $orderId) {}

    /** @return list<int> seconds between attempts */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(DeliveryService $delivery): void
    {
        $order = Order::query()->with('buyer')->findOrFail($this->orderId);
        $delivery->deliver($order);
        Mail::to($order->buyer)->queue(new OrderPaidMail($order->fresh()));
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
