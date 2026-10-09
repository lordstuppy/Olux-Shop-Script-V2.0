<?php

namespace Database\Seeders;

use App\Enums\DeliveryType;
use App\Enums\ProductStatus;
use App\Enums\SellerProfileStatus;
use App\Models\Category;
use App\Models\ExchangeRate;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Demo data for local development and the browser tests. Never run in
 * production: it creates accounts with a known password.
 */
class DatabaseSeeder extends Seeder
{
    public const PASSWORD = 'correct-horse-battery-1';

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('Refusing to seed demo data in production.');

            return;
        }

        $admin = User::factory()->admin()->create(['name' => 'Admin', 'email' => 'admin@example.test']);
        $seller = User::factory()->seller()->create(['name' => 'Demo Seller', 'email' => 'seller@example.test']);
        User::factory()->withBalance(10000)->create(['name' => 'Demo Buyer', 'email' => 'buyer@example.test']);

        SellerProfile::create([
            'user_id' => $seller->id,
            'display_name' => 'Demo Software Studio',
            'payout_currency' => 'USD',
            'payout_address' => 'bc1qdemoaddressxxxxxxxxxxxxxxxxxxxxxxxxxx',
            'status' => SellerProfileStatus::Approved,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
        ]);

        $categories = collect([
            ['slug' => 'tools', 'name' => 'Software tools'],
            ['slug' => 'tutorials', 'name' => 'Tutorials'],
            ['slug' => 'licences', 'name' => 'Licences'],
            ['slug' => 'subscriptions', 'name' => 'Subscriptions'],
        ])->map(fn ($c) => Category::create($c))->keyBy('slug');

        ExchangeRate::create(['base' => 'USD', 'quote' => 'EUR', 'rate' => '0.9200000000', 'updated_by' => $admin->id]);
        ExchangeRate::create(['base' => 'EUR', 'quote' => 'USD', 'rate' => '1.0870000000', 'updated_by' => $admin->id]);

        $products = [
            ['tools', 'Markdown to PDF converter', 'A command-line tool that converts Markdown documents to print-ready PDF files.', 1900, 'USD', null, DeliveryType::Instant],
            ['tutorials', 'PostgreSQL performance tuning guide', 'A 120-page guide to indexes, query plans and connection pooling.', 2500, 'EUR', null, DeliveryType::Instant],
            ['licences', 'Photo editor pro licence', 'Single-user licence key for the Photo Editor Pro desktop app.', 4900, 'USD', null, DeliveryType::Instant],
            ['subscriptions', 'Icon pack yearly subscription', 'One year of access to monthly icon pack releases, delivered by the seller.', 2900, 'USD', 50, DeliveryType::Manual],
        ];

        foreach ($products as [$category, $title, $description, $price, $currency, $stock, $delivery]) {
            $product = Product::factory()->create([
                'seller_id' => $seller->id,
                'category_id' => $categories[$category]->id,
                'title' => $title,
                'slug' => Str::slug($title),
                'description' => $description,
                'price_minor' => $price,
                'currency' => $currency,
                'stock' => $stock,
                'delivery_type' => $delivery,
                'status' => ProductStatus::Active,
            ]);

            if ($delivery === DeliveryType::Instant && $category !== 'licences') {
                $path = $product->id.'/sample.txt';
                $contents = "Sample deliverable for {$title}.\n";
                Storage::disk('products')->put($path, $contents);
                $product->files()->create([
                    'original_name' => 'sample.txt',
                    'storage_path' => $path,
                    'checksum' => hash('sha256', $contents),
                    'size' => strlen($contents),
                ]);
            }
            if ($category === 'licences') {
                foreach (range(1, 5) as $i) {
                    $key = sprintf('DEMO-%04d-%04d', $product->id, $i);
                    $product->licenseKeys()->create([
                        'key_encrypted' => $key,
                        'key_fingerprint' => hash_hmac('sha256', $key, (string) config('app.key')),
                    ]);
                }
                $product->update(['stock' => 5]);
            }
        }
    }
}
