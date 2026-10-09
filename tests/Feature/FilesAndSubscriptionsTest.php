<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\ProductStatus;
use App\Jobs\ScanProductFile;
use App\Mail\SubscriptionRenewalMail;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductFile;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DeliveryService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\Security\VirusScanner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class FilesAndSubscriptionsTest extends TestCase
{
    private function fakeScanner(string $status, ?string $detail = null): void
    {
        $this->app->instance(VirusScanner::class, new class($status, $detail) implements VirusScanner
        {
            public function __construct(private string $status, private ?string $detail) {}

            public function scan(string $absolutePath): array
            {
                return ['status' => $this->status, 'detail' => $this->detail];
            }
        });
    }

    private function sellerWithDraft(): array
    {
        $seller = User::factory()->seller()->create();
        $product = Product::factory()->status(ProductStatus::Draft)->create(['seller_id' => $seller->id]);

        return [$seller, $product];
    }

    private function buy(Product $product, int $qty = 1): Order
    {
        $buyer = User::factory()->withBalance($product->price_minor * $qty)->create();
        $order = app(OrderService::class)->createFromCart($buyer, [$product->id => $qty], 'USD', (string) Str::uuid());
        app(PaymentService::class)->payWithBalance($order, $buyer);

        return $order->fresh();
    }

    public function test_uploaded_file_is_scanned_and_clean_file_can_be_approved(): void
    {
        $this->fakeScanner('clean');
        [$seller, $product] = $this->sellerWithDraft();
        $this->actingAs($seller);
        $this->postForm(route('seller.products.files.store', $product->id), ['file' => UploadedFile::fake()->createWithContent('tool.zip', 'zip')])->assertSessionHas('success');

        $file = ProductFile::firstOrFail();
        $this->assertSame('clean', $file->scan_status);
        $this->assertNotNull($file->scanned_at);

        $this->postForm(route('seller.products.submit', $product->id));
        $this->actingAs(User::factory()->admin()->create());
        $this->get(route('admin.products.show', $product->id))->assertOk()->assertSee('tool.zip');
        $download = $this->get(route('admin.products.files.download', [$product->id, $file->id]));
        $download->assertOk();
        $this->assertDatabaseHas('audit_log', ['action' => 'product.file_inspected']);
        $this->postForm(route('admin.products.status', $product->id), ['status' => 'active'])->assertSessionHas('success');
        $this->assertSame(ProductStatus::Active, $product->fresh()->status);
    }

    public function test_infected_file_is_removed_and_product_disabled(): void
    {
        $this->fakeScanner('infected', 'Eicar-Test-Signature');
        [$seller, $product] = $this->sellerWithDraft();
        $this->actingAs($seller);
        $this->postForm(route('seller.products.files.store', $product->id), ['file' => UploadedFile::fake()->createWithContent('evil.zip', 'x')]);

        $file = ProductFile::firstOrFail();
        $this->assertSame('infected', $file->scan_status);
        $this->assertNotNull($file->retired_at);
        Storage::disk('products')->assertMissing($file->storage_path);
        $this->assertSame(ProductStatus::Disabled, $product->fresh()->status);
        $this->assertDatabaseHas('audit_log', ['action' => 'product.file_infected']);

        // With its only file removed there is nothing to deliver, so staff
        // cannot approve it and the seller cannot resubmit it.
        $this->postForm(route('seller.products.submit', $product->id))
            ->assertSessionHas('error', 'Instant-delivery products need at least one file or licence key before review.');
        $this->actingAs(User::factory()->admin()->create());
        $this->postForm(route('admin.products.status', $product->id), ['status' => 'active'])
            ->assertSessionHas('error', '"'.$product->title.'" has nothing to deliver: it needs a file or licence keys before it can be approved.');
        $this->assertSame(ProductStatus::Disabled, $product->fresh()->status);
    }

    public function test_scanner_outage_blocks_approval_and_retries(): void
    {
        [$seller, $product] = $this->sellerWithDraft();
        $file = $product->files()->create(['original_name' => 'a.zip', 'storage_path' => $product->id.'/a.zip', 'checksum' => str_repeat('0', 64), 'size' => 1]);
        Storage::disk('products')->put($product->id.'/a.zip', 'a');

        $this->fakeScanner('error', 'clamd unreachable');
        try {
            (new ScanProductFile($file->id))->handle(app(VirusScanner::class), app(AuditLogger::class));
            $this->fail('A scanner outage must make the job fail so the queue retries it');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('clamd unreachable', $e->getMessage());
        }
        $this->assertSame('error', $file->fresh()->scan_status);

        $this->actingAs(User::factory()->admin()->create());
        $this->postForm(route('admin.products.status', $product->id), ['status' => 'active'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'without a clean virus scan'));

        $this->fakeScanner('clean');
        (new ScanProductFile($file->id))->handle(app(VirusScanner::class), app(AuditLogger::class));
        $this->assertSame('clean', $file->fresh()->scan_status);
    }

    public function test_unscanned_files_are_not_delivered(): void
    {
        $product = Product::factory()->price(500)->create();
        $product->files()->create(['original_name' => 'p.zip', 'storage_path' => 'x/p.zip', 'checksum' => str_repeat('0', 64), 'size' => 1, 'scan_status' => 'pending']);

        $order = $this->buy($product);
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertNull($order->items()->first()->delivered_at);
        $this->assertDatabaseHas('audit_log', ['action' => 'delivery.failed']);
    }

    public function test_images_are_reencoded_and_served(): void
    {
        [$seller, $product] = $this->sellerWithDraft();
        $this->actingAs($seller);

        $png = UploadedFile::fake()->image('shot.png', 2400, 1200);
        $this->postForm(route('seller.products.images.store', $product->id), ['image' => $png, 'alt_text' => 'Main window'])
            ->assertSessionHas('success', 'Image added (1600 x 800).');
        $image = $product->images()->firstOrFail();
        $this->assertStringStartsWith('RIFF', Storage::disk('product_images')->get($image->path));

        // Fake uploads pass the MIME rule; the GD decode in the service is the second line of defence.
        $this->postForm(route('seller.products.images.store', $product->id), ['image' => UploadedFile::fake()->createWithContent('x.png', '<?php echo 1;')])
            ->assertSessionHas('error', 'The image must be a JPEG, PNG or WebP file.');
        $this->assertSame(1, $product->images()->count());

        // Unlisted: only the seller (and staff) can see it.
        $this->get($image->url('thumb'))->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->postForm('/logout');
        $this->get($image->url('thumb'))->assertNotFound();

        $product->update(['status' => ProductStatus::Active]);
        $this->get($image->url())->assertOk()->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');
        $this->get(route('products.show', $product->slug))->assertSee($image->url(), false)->assertSee('Main window');
    }

    public function test_download_limit_is_enforced(): void
    {
        $product = $this->instantProductWithFile(['price_minor' => 500, 'download_limit' => 2]);
        $order = $this->buy($product);
        $item = $order->items()->first();
        $url = app(DeliveryService::class)->downloadUrl($order, $item, $product->files->first());

        $this->actingAs($order->buyer);
        $this->get($url)->assertOk();
        $this->get($url)->assertOk();
        $this->get($url)->assertForbidden()->assertSee('download limit of 2');
        $this->assertSame(2, $item->fresh()->download_count);
        $this->get(route('orders.show', $order))->assertSee('Downloads used: 2 of 2.');
    }

    public function test_subscription_access_expires_and_reminder_is_sent_once(): void
    {
        Mail::fake();
        $product = $this->instantProductWithFile(['price_minor' => 500, 'access_days' => 30]);
        $order = $this->buy($product, 2);
        $item = $order->items()->first();
        $this->assertEqualsWithDelta(now()->addDays(60)->timestamp, $item->access_expires_at->timestamp, 5);

        $this->travel(55)->days();
        $this->artisan('shop:subscription-reminders')->assertSuccessful();
        $this->artisan('shop:subscription-reminders')->assertSuccessful();
        Mail::assertQueued(SubscriptionRenewalMail::class, 1);

        $this->travel(6)->days();
        $url = app(DeliveryService::class)->downloadUrl($order, $item, $product->files->first());
        $this->actingAs($order->buyer)->get($url)->assertForbidden()->assertSee('Access to this subscription ended');
        $this->get(route('orders.show', $order))->assertSee('Access ended');
    }
}
