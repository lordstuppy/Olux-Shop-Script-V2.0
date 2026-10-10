<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Jobs\DeliverOrder;
use App\Jobs\GenerateInvoicePdf;
use App\Services\AuditLogger;
use App\Services\OrderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class StuckOrderRecoveryTest extends TestCase
{
    public function test_paid_orders_whose_jobs_were_lost_are_requeued_once(): void
    {
        $product = $this->instantProductWithFile();
        $order = app(OrderService::class)->createFromCart($this->buyer(), [$product->id => 1], 'USD', (string) Str::uuid());
        // Paid, but the process died before the delivery and invoice jobs were queued.
        DB::table('orders')->where('id', $order->id)->update(['status' => OrderStatus::Paid->value, 'paid_at' => now()->subMinutes(10)]);
        Queue::fake();

        $this->artisan('shop:recover-stuck-orders')->assertSuccessful();
        Queue::assertPushed(DeliverOrder::class, fn ($job) => $job->orderId === $order->id);
        Queue::assertPushed(GenerateInvoicePdf::class, fn ($job) => $job->orderId === $order->id);

        $this->artisan('shop:recover-stuck-orders')->assertSuccessful();
        Queue::assertPushed(DeliverOrder::class, 1);
    }

    public function test_fresh_and_manual_orders_are_left_alone(): void
    {
        $product = $this->instantProductWithFile();
        $order = app(OrderService::class)->createFromCart($this->buyer(), [$product->id => 1], 'USD', (string) Str::uuid());
        DB::table('orders')->where('id', $order->id)->update(['status' => OrderStatus::Paid->value, 'paid_at' => now()->subMinute()]);
        Queue::fake();
        $this->artisan('shop:recover-stuck-orders')->assertSuccessful();
        Queue::assertNothingPushed();
    }

    public function test_lines_whose_delivery_already_failed_wait_for_staff(): void
    {
        $product = $this->instantProductWithFile();
        $order = app(OrderService::class)->createFromCart($this->buyer(), [$product->id => 1], 'USD', (string) Str::uuid());
        DB::table('orders')->where('id', $order->id)->update(['status' => OrderStatus::Paid->value, 'paid_at' => now()->subMinutes(10)]);
        DB::table('invoices')->insert(['order_id' => $order->id, 'number' => 'INV-T-1', 'issued_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->log('delivery.failed', $order->items()->first(), ['reason' => 'no keys']);
        Queue::fake();
        $this->artisan('shop:recover-stuck-orders')->assertSuccessful();
        Queue::assertNothingPushed();
    }
}
