<?php

namespace Tests\Feature;

use App\Enums\PayoutStatus;
use App\Enums\ProductStatus;
use App\Enums\UserRole;
use App\Jobs\ScanProductFile;
use App\Mail\ProductDecisionMail;
use App\Mail\ReviewReceivedMail;
use App\Mail\SellerSaleMail;
use App\Mail\StaffAlertMail;
use App\Models\Payout;
use App\Models\Product;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\Security\VirusScanner;
use App\Support\GatewayLog;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['shop.staff_alert_email' => 'ops@shop.test']);
    }

    private function staffAlerts(): array
    {
        return Mail::queued(StaffAlertMail::class)->merge(Mail::sent(StaffAlertMail::class))
            ->filter(fn ($mail) => $mail->hasTo('ops@shop.test'))->values()->all();
    }

    private function buy(Product $product, ?User $buyer = null): void
    {
        $buyer ??= User::factory()->withBalance($product->price_minor)->create();
        $order = app(OrderService::class)->createFromCart($buyer, [$product->id => 1], 'USD', (string) Str::uuid());
        app(PaymentService::class)->payWithBalance($order, $buyer);
    }

    public function test_sellers_can_turn_off_sale_and_review_emails(): void
    {
        $seller = User::factory()->seller()->create();
        $product = $this->instantProductWithFile(['seller_id' => $seller->id, 'price_minor' => 1000]);

        $this->buy($product);
        Mail::assertQueued(SellerSaleMail::class, 1);

        $buyer = User::factory()->withBalance(1000)->create();
        $this->buy($product, $buyer);
        $this->actingAs($buyer)->postForm(route('products.reviews.store', $product->slug), ['rating' => 2, 'title' => 'Meh', 'body' => 'Slow.']);
        Mail::assertQueued(ReviewReceivedMail::class, fn ($mail) => $mail->hasTo($seller->email) && $mail->review->rating === 2);
        // Editing the review does not mail again.
        $this->postForm(route('products.reviews.store', $product->slug), ['rating' => 3, 'title' => 'Better', 'body' => 'Fixed.']);
        Mail::assertQueued(ReviewReceivedMail::class, 1);

        $this->actingAs($seller)->putForm(route('account.notifications'), [])->assertSessionHas('success');
        $this->assertFalse($seller->fresh()->notify_sales);
        $this->assertFalse($seller->fresh()->notify_reviews);

        $this->buy($product);
        Mail::assertQueued(SellerSaleMail::class, 2);
        $this->actingAs($seller)->putForm(route('account.notifications'), ['notify_sales' => '1'])->assertSessionHas('success');
        $this->buy($product);
        Mail::assertQueued(SellerSaleMail::class, 3);
        $this->get(route('account.settings'))->assertSee('name="notify_sales" value="1" checked', false);
    }

    public function test_an_infected_upload_tells_the_seller_and_staff(): void
    {
        $seller = User::factory()->seller()->create();
        $product = $this->instantProductWithFile(['seller_id' => $seller->id]);
        $this->app->instance(VirusScanner::class, new class implements VirusScanner
        {
            public function scan(string $absolutePath): array
            {
                return ['status' => 'infected', 'detail' => 'Eicar-Test-Signature'];
            }

            public function ping(): array
            {
                return ['ok' => true, 'detail' => ''];
            }
        });
        config(['shop.virus_scan' => 'required']);

        $file = $product->files()->first();
        $file->forceFill(['scan_status' => 'pending'])->save();
        (new ScanProductFile($file->id))->handle(app(VirusScanner::class), app(AuditLogger::class));

        $this->assertSame(ProductStatus::Disabled, $product->fresh()->status);
        Mail::assertQueued(ProductDecisionMail::class, fn ($mail) => $mail->hasTo($seller->email) && ! $mail->approved && str_contains($mail->note, 'virus scan'));
        $this->assertCount(1, $this->staffAlerts());
        $this->assertStringContainsString('Eicar-Test-Signature', implode("\n", $this->staffAlerts()[0]->lines));
    }

    public function test_rejected_callbacks_alert_staff_once_an_hour(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.(10 + $i)])
                ->postShkeeperWebhook($this->paidPayload('x', '1.00'), secret: 'forged')->assertStatus(401);
        }

        $alerts = $this->staffAlerts();
        $this->assertCount(1, $alerts);
        $this->assertSame('Shkeeper callbacks are being rejected', $alerts[0]->alertSubject);
    }

    public function test_a_job_that_used_up_its_retries_alerts_staff_and_shows_on_the_dashboard(): void
    {
        $job = new FakeJob;
        $job->payload = ['displayName' => 'App\\Mail\\OrderPaidMail'];
        $fake = new class($job) extends FakeJob
        {
            public function __construct(private FakeJob $inner) {}

            public function resolveName()
            {
                return 'App\\Mail\\OrderPaidMail';
            }
        };
        event(new JobFailed('database', $fake, new RuntimeException('Connection could not be established with host mail:1025')));
        event(new JobFailed('database', $fake, new RuntimeException('Connection could not be established with host mail:1025')));

        $this->assertCount(1, $this->staffAlerts(), 'one alert per job type and half hour');
        $this->assertStringContainsString('OrderPaidMail', $this->staffAlerts()[0]->alertSubject);

        // An alert mail that fails itself does not alert again (no loop).
        $alertJob = new class extends FakeJob
        {
            public function resolveName()
            {
                return StaffAlertMail::class;
            }
        };
        event(new JobFailed('database', $alertJob, new RuntimeException('mail down')));
        $this->assertCount(1, $this->staffAlerts());
    }

    public function test_failed_jobs_can_be_retried_or_deleted_by_managers_only(): void
    {
        $uuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert(['uuid' => $uuid, 'connection' => 'database', 'queue' => 'default',
            'payload' => json_encode(['uuid' => $uuid, 'displayName' => 'App\\Mail\\OrderPaidMail', 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'data' => ['command' => 'x']]),
            'exception' => "Symfony\\Component\\Mailer\\Exception\\TransportException: Connection refused\n#0 trace", 'failed_at' => now()]);

        $this->actingAs(User::factory()->staff(UserRole::Finance)->create());
        $this->get(route('admin.dashboard'))->assertSee('Failed background jobs (emails, deliveries, invoices)');
        $this->get(route('admin.health'))->assertOk()->assertSee('OrderPaidMail')->assertSee('Connection refused')->assertDontSee('Retry all failed jobs');
        $this->postForm(route('admin.health.jobs.retry', $uuid))->assertForbidden();

        $this->actingAs(User::factory()->staff(UserRole::Manager)->create());
        $this->get(route('admin.health'))->assertSee('Retry all failed jobs');
        $this->deleteForm(route('admin.health.jobs.forget', $uuid))->assertSessionHas('success');
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->postForm(route('admin.health.jobs.retry', $uuid))->assertSessionHas('error');
        $this->postForm(route('admin.health.jobs.retry', 'not-a-uuid'))->assertSessionHas('error');
    }

    public function test_the_digest_lists_new_work_once(): void
    {
        $seller = User::factory()->seller()->create();
        Product::factory()->status(ProductStatus::PendingReview)->create(['seller_id' => $seller->id]);
        Payout::create(['seller_id' => $seller->id, 'amount_minor' => 1000, 'currency' => 'USD', 'destination' => 'bc1q', 'status' => PayoutStatus::Requested]);
        Ticket::create(['user_id' => User::factory()->create()->id, 'subject' => 'Help', 'status' => 'open', 'category' => 'support']);

        $this->artisan('shop:staff-digest')->assertSuccessful();
        $alerts = $this->staffAlerts();
        $this->assertCount(1, $alerts);
        $body = implode("\n", $alerts[0]->lines);
        $this->assertStringContainsString('1 products submitted for review', $body);
        $this->assertStringContainsString('1 payout requests', $body);
        $this->assertStringContainsString('1 new support tickets', $body);
        $this->assertStringNotContainsString('seller applications', $body);

        $this->travel(1)->minutes();
        $this->artisan('shop:staff-digest')->assertSuccessful();
        $this->assertCount(1, $this->staffAlerts(), 'nothing new, no mail');
    }

    public function test_a_gateway_outage_is_reported_directly_and_at_most_hourly(): void
    {
        foreach (range(1, 3) as $i) {
            GatewayLog::record('api', 'timeout', ['action' => 'quote', 'message' => 'cURL error 28']);
        }

        $this->artisan('shop:health-alerts')->assertSuccessful();
        Mail::assertSent(StaffAlertMail::class, fn ($mail) => $mail->hasTo('ops@shop.test') && str_contains($mail->alertSubject, 'Shkeeper API errors'));
        $sent = count($this->staffAlerts());

        $this->artisan('shop:health-alerts')->assertSuccessful();
        $this->assertCount($sent, $this->staffAlerts());

        GatewayLog::record('api', 'ok', ['action' => 'quote']);
        $this->actingAs(User::factory()->admin()->create());
        $this->get(route('admin.health'))->assertSee('Shkeeper API errors (last 15 minutes)');
    }

    public function test_a_locked_staff_account_alerts_staff_but_a_locked_customer_does_not(): void
    {
        config(['shop.login_lock' => ['attempts' => 3, 'window_minutes' => 15, 'lock_minutes' => 15]]);
        $customer = User::factory()->create();
        $moderator = User::factory()->staff(UserRole::Moderator)->create();

        foreach ([$customer, $moderator] as $user) {
            for ($i = 0; $i < 3; $i++) {
                $this->postForm(route('login'), ['email' => $user->email, 'password' => 'wrong-password-'.$i]);
            }
        }

        $alerts = $this->staffAlerts();
        $this->assertCount(1, $alerts);
        $this->assertStringContainsString($moderator->email, implode("\n", $alerts[0]->lines));
    }
}
