<?php

namespace Database\Seeders;

use App\Enums\OrderStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Enums\ProductStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Fixtures for tests/load: buyers with large balances, one hot product that
 * every virtual user buys (row-lock contention), and pending Shkeeper orders
 * for the webhook scenario. Run on a disposable database only:
 *   php artisan db:seed --class=Database\\Seeders\\LoadTestSeeder
 */
class LoadTestSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('Refusing to seed load-test data in production.');

            return;
        }

        $seller = User::factory()->seller()->create(['email' => 'load-seller@example.test']);
        $product = Product::factory()->create([
            'seller_id' => $seller->id,
            'slug' => 'load-test-product',
            'title' => 'Load test product',
            'price_minor' => 100,
            'stock' => 1000000,
            'status' => ProductStatus::Active,
        ]);
        Storage::disk('products')->put($product->id.'/load.txt', 'load');
        $product->files()->create(['original_name' => 'load.txt', 'storage_path' => $product->id.'/load.txt', 'checksum' => hash('sha256', 'load'), 'size' => 4]);

        $users = (int) (getenv('LOAD_USERS') ?: 50);
        for ($i = 1; $i <= $users; $i++) {
            User::factory()->withBalance(100000000)->create(['email' => "load{$i}@example.test", 'name' => "Load {$i}"]);
        }

        // Pending orders with Shkeeper invoices for the webhook scenario.
        $buyer = User::factory()->create(['email' => 'load-webhook@example.test']);
        $orders = (int) (getenv('LOAD_WEBHOOK_ORDERS') ?: 500);
        $ids = [];
        for ($i = 1; $i <= $orders; $i++) {
            $order = Order::create([
                'public_id' => (string) Str::uuid(),
                'buyer_id' => $buyer->id,
                'status' => OrderStatus::Pending,
                'subtotal_minor' => 100,
                'total_minor' => 100,
                'currency' => 'USD',
                'idempotency_key' => 'load-'.$i.'-'.Str::random(12),
                'expires_at' => now()->addDay(),
            ]);
            $order->items()->create([
                'product_id' => $product->id, 'seller_id' => $seller->id, 'title' => $product->title,
                'unit_price_minor' => 100, 'quantity' => 1, 'list_price_minor' => 100, 'list_currency' => 'USD',
                'fx_rate' => '1', 'commission_bps' => 1000,
            ]);
            Payment::create([
                'order_id' => $order->id, 'kind' => PaymentKind::Charge, 'provider' => PaymentProvider::Shkeeper,
                'provider_reference' => 'load-'.$i, 'amount_minor' => 100, 'currency' => 'USD', 'status' => PaymentStatus::Pending,
                'crypto' => 'BTC', 'crypto_amount' => '0.0000017', 'wallet_address' => 'bc1qload',
            ]);
            $ids[] = $order->public_id;
        }

        file_put_contents(base_path('tests/load/webhook-orders.json'), json_encode($ids));
        $this->command?->info("Load fixtures: {$users} buyers, {$orders} pending orders written to tests/load/webhook-orders.json");
    }
}
