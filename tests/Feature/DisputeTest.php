<?php

namespace Tests\Feature;

use App\Enums\DisputeResolution;
use App\Enums\DisputeStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Jobs\DeliverOrder;
use App\Mail\DisputeEscalatedMail;
use App\Mail\DisputeMessageMail;
use App\Mail\DisputeOpenedMail;
use App\Mail\DisputeResolvedMail;
use App\Models\Dispute;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\PayoutService;
use App\Services\Shkeeper\ShkeeperClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class DisputeTest extends TestCase
{
    private User $buyer;

    private Product $keyProduct;

    private Product $otherSellerProduct;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['shop.payout_hold_days' => 0, 'shop.support_email' => 'support@shop.test']);

        // One order with lines from two different sellers.
        $this->keyProduct = $this->productFromNewSeller(['price_minor' => 4000]);
        foreach (['KEY-ONE', 'KEY-TWO', 'KEY-THREE'] as $key) {
            $this->keyProduct->licenseKeys()->create(['key_encrypted' => $key, 'key_fingerprint' => hash('sha256', $key)]);
        }
        $this->otherSellerProduct = $this->productFromNewSeller(['price_minor' => 6000]);

        $this->buyer = User::factory()->withBalance(10000)->create();
        $this->order = app(OrderService::class)->createFromCart($this->buyer, [$this->keyProduct->id => 1, $this->otherSellerProduct->id => 1], 'USD', (string) Str::uuid());
        app(PaymentService::class)->payWithBalance($this->order, $this->buyer);
        DeliverOrder::dispatchSync($this->order->id);
    }

    private function productFromNewSeller(array $attributes): Product
    {
        $seller = User::factory()->seller()->create();
        SellerProfile::create(['user_id' => $seller->id, 'display_name' => 'Shop '.$seller->id, 'status' => 'approved', 'payout_currency' => 'USD',
            'payout_crypto' => 'BTC', 'payout_address' => 'bc1qtestaddress00']);

        return $this->instantProductWithFile(['seller_id' => $seller->id] + $attributes);
    }

    private function keyItem(): OrderItem
    {
        return $this->order->items()->where('product_id', $this->keyProduct->id)->firstOrFail();
    }

    private function openDispute(string $outcome = 'replacement'): Dispute
    {
        $this->actingAs($this->buyer);
        $this->postForm(route('disputes.store', [$this->order, $this->keyItem()]), [
            'reason' => 'not_working', 'requested_outcome' => $outcome, 'body' => 'The key says it is already in use.',
        ])->assertRedirect();

        return Dispute::firstOrFail();
    }

    private function staff(UserRole $role = UserRole::Moderator): User
    {
        $user = User::factory()->staff($role)->create();
        $this->actingAs($user)->confirmPassword();

        return $user;
    }

    public function test_buyer_opens_a_dispute_and_the_sellers_earnings_are_held(): void
    {
        $seller = $this->keyProduct->seller;
        $before = app(PayoutService::class)->balances($seller)['USD']['available'];
        $this->assertSame(3600, $before);

        $this->actingAs($this->buyer)->get(route('orders.show', $this->order))->assertSee('Report a problem with this item');
        $this->get(route('disputes.create', [$this->order, $this->keyItem()]))->assertOk()->assertSee('What went wrong?');
        $dispute = $this->openDispute();

        $this->assertSame(DisputeStatus::AwaitingSeller, $dispute->status);
        $this->assertSame($seller->id, $dispute->seller_id);
        $this->assertTrue($dispute->seller_respond_by->between(now()->addDays(3)->subMinute(), now()->addDays(3)->addMinute()));
        Mail::assertQueued(DisputeOpenedMail::class, fn ($m) => $m->hasTo($seller->email) && $m->recipientRole === 'seller');
        Mail::assertQueued(DisputeOpenedMail::class, fn ($m) => $m->hasTo('support@shop.test'));
        $this->assertDatabaseHas('audit_log', ['action' => 'dispute.opened']);

        $balance = app(PayoutService::class)->balances($seller)['USD'];
        $this->assertSame(3600, $balance['disputed']);
        $this->assertSame(0, $balance['available']);
        // The other seller in the same order is not affected.
        $this->assertSame(5400, app(PayoutService::class)->balances($this->otherSellerProduct->seller)['USD']['available']);

        $this->get(route('orders.show', $this->order))->assertSee('Dispute #'.$dispute->id);
        $this->get(route('disputes.show', $dispute))->assertOk()->assertSee('The key says it is already in use.');
    }

    public function test_one_dispute_per_line_within_the_window_for_the_buyer_only(): void
    {
        $item = $this->keyItem();
        $this->actingAs(User::factory()->create());
        $this->postForm(route('disputes.store', [$this->order, $item]), ['reason' => 'other', 'requested_outcome' => 'refund', 'body' => 'Not my order at all.'])->assertForbidden();

        $this->openDispute();
        $this->postForm(route('disputes.store', [$this->order, $item]), ['reason' => 'other', 'requested_outcome' => 'refund', 'body' => 'Trying a second time.'])
            ->assertSessionHas('error', 'A dispute for "'.$item->title.'" already exists.');
        $this->assertSame(1, Dispute::count());

        $other = $this->order->items()->where('product_id', $this->otherSellerProduct->id)->firstOrFail();
        $this->travel(15)->days();
        $this->get(route('disputes.create', [$this->order, $other]))->assertRedirect(route('orders.show', $this->order));
        $this->postForm(route('disputes.store', [$this->order, $other]), ['reason' => 'other', 'requested_outcome' => 'refund', 'body' => 'Too late for this one.'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'The dispute window for "'.$other->title.'" closed on'));
    }

    public function test_seller_response_hands_the_case_to_staff_and_internal_notes_stay_internal(): void
    {
        $dispute = $this->openDispute();
        $seller = $this->keyProduct->seller;

        $this->actingAs($seller)->get(route('disputes.show', $dispute))->assertOk()->assertSee('Respond to the buyer');
        $this->postForm(route('disputes.reply', $dispute), ['body' => 'The key works for me; please retry offline.'])->assertSessionHas('success');
        $this->assertSame(DisputeStatus::AwaitingStaff, $dispute->fresh()->status);
        $this->assertNotNull($dispute->fresh()->seller_responded_at);
        Mail::assertQueued(DisputeMessageMail::class, fn ($m) => $m->hasTo($this->buyer->email) && $m->fromRole === 'seller');

        $this->staff();
        $this->postForm(route('disputes.reply', $dispute), ['body' => 'Seller has had issues before.', 'internal' => '1']);
        $this->actingAs($this->buyer)->get(route('disputes.show', $dispute))->assertSee('please retry offline')->assertDontSee('Seller has had issues before.');
        $this->actingAs($seller)->get(route('disputes.show', $dispute))->assertDontSee('Seller has had issues before.');

        // A buyer cannot post internal notes.
        $this->actingAs($this->buyer);
        $this->postForm(route('disputes.reply', $dispute), ['body' => 'Hidden?', 'internal' => '1']);
        $this->assertFalse($dispute->messages()->where('body', 'Hidden?')->firstOrFail()->internal);
    }

    public function test_unanswered_disputes_escalate_after_the_deadline(): void
    {
        $dispute = $this->openDispute();
        $this->artisan('shop:escalate-disputes')->expectsOutputToContain('Escalated 0')->assertSuccessful();

        $this->travel(3)->days();
        $this->travel(1)->minutes();
        $this->artisan('shop:escalate-disputes')->expectsOutputToContain('Escalated 1')->assertSuccessful();
        $dispute->refresh();
        $this->assertSame(DisputeStatus::AwaitingStaff, $dispute->status);
        $this->assertNotNull($dispute->escalated_at);
        Mail::assertQueued(DisputeEscalatedMail::class, 3);
        $this->assertDatabaseHas('dispute_messages', ['dispute_id' => $dispute->id, 'author_role' => 'system']);
    }

    public function test_refund_comes_out_of_the_disputed_line_only(): void
    {
        $dispute = $this->openDispute('refund');
        $this->staff(UserRole::Manager);
        $this->get(route('disputes.show', $dispute))->assertOk()->assertSee('Refund this item')->assertSee('Refundable: 40.00 USD');

        $this->postForm(route('admin.disputes.resolve', $dispute), ['action' => 'refund', 'amount' => '50.00', 'method' => 'balance'])
            ->assertSessionHas('error', 'Refund amount 50.00 USD exceeds the refundable 40.00 USD for "'.$this->keyItem()->title.'".');

        $this->postForm(route('admin.disputes.resolve', $dispute), ['action' => 'refund', 'amount' => '40.00', 'method' => 'balance', 'note' => 'Key was faulty.'])
            ->assertRedirect(route('disputes.show', $dispute))->assertSessionHas('success', 'Dispute #'.$dispute->id.' resolved: refunded.');

        $dispute->refresh();
        $this->assertSame(DisputeResolution::Refund, $dispute->resolution);
        $this->assertSame(4000, $dispute->refund_minor);
        $this->assertSame(4000, $this->keyItem()->refunded_minor);
        $this->assertSame(0, $this->order->items()->where('product_id', $this->otherSellerProduct->id)->value('refunded_minor'));
        $this->assertSame(4000, $this->buyer->fresh()->balance_minor);

        $payouts = app(PayoutService::class);
        $this->assertSame(['total' => 0, 'pending' => 0, 'disputed' => 0, 'available' => 0], $payouts->balances($this->keyProduct->seller)['USD']);
        $this->assertSame(5400, $payouts->balances($this->otherSellerProduct->seller)['USD']['available']);
        $this->assertSame([], $payouts->reconcile());
        Mail::assertQueued(DisputeResolvedMail::class, 2);

        $this->postForm(route('admin.disputes.resolve', $dispute), ['action' => 'reject', 'note' => 'Again?'])->assertSessionHas('error', 'Dispute #'.$dispute->id.' is already closed.');
    }

    public function test_replacement_delivers_a_fresh_licence_key(): void
    {
        $dispute = $this->openDispute();
        $oldKey = $this->keyItem()->delivered_payload['license_keys'][0];
        $this->staff();
        $this->postForm(route('admin.disputes.resolve', $dispute), ['action' => 'replacement'])->assertSessionHas('success');

        $payload = $this->keyItem()->delivered_payload;
        $this->assertNotSame($oldKey, $payload['license_keys'][0]);
        $this->assertSame([$oldKey], $payload['replaced_license_keys']);
        $this->assertSame(DisputeResolution::Replacement, $dispute->fresh()->resolution);
        $this->assertStringContainsString('1 new licence key', $dispute->fresh()->resolution_note);
        $this->actingAs($this->buyer)->get(route('orders.show', $this->order))->assertSee($payload['license_keys'][0]);
        $this->assertSame(3600, app(PayoutService::class)->balances($this->keyProduct->seller)['USD']['available']);
    }

    public function test_rejection_needs_a_reason_and_releases_the_hold(): void
    {
        $dispute = $this->openDispute();
        $this->staff();
        $this->postForm(route('admin.disputes.resolve', $dispute), ['action' => 'reject', 'note' => ''])
            ->assertSessionHas('error', 'Explain to the buyer why the dispute is rejected.');
        $this->postForm(route('admin.disputes.resolve', $dispute), ['action' => 'reject', 'note' => 'The key was activated by you on 3 devices.'])->assertSessionHas('success');
        $this->assertSame(DisputeResolution::Rejected, $dispute->fresh()->resolution);
        $this->assertSame(3600, app(PayoutService::class)->balances($this->keyProduct->seller)['USD']['available']);
        $this->actingAs($this->buyer)->get(route('disputes.show', $dispute))->assertSee('The key was activated by you on 3 devices.');
    }

    public function test_buyer_can_withdraw(): void
    {
        $dispute = $this->openDispute();
        $this->postForm(route('disputes.withdraw', $dispute))->assertSessionHas('success');
        $this->assertSame(DisputeStatus::Withdrawn, $dispute->fresh()->status);
        $this->postForm(route('disputes.reply', $dispute), ['body' => 'More'])->assertForbidden();
    }

    public function test_only_dispute_staff_resolve_and_staff_need_two_factor(): void
    {
        $dispute = $this->openDispute();

        foreach ([UserRole::Support, UserRole::Finance] as $role) {
            $this->staff($role);
            $this->get(route('disputes.show', $dispute))->assertForbidden();
            $this->postForm(route('admin.disputes.resolve', $dispute), ['action' => 'reject', 'note' => 'No'])->assertForbidden();
        }
        $this->actingAs($this->keyProduct->seller);
        $this->postForm(route('admin.disputes.resolve', $dispute), ['action' => 'reject', 'note' => 'No'])->assertForbidden();

        // A moderator without two-factor authentication has no staff abilities anywhere.
        $this->actingAs(User::factory()->create(['role' => UserRole::Moderator]));
        $this->get(route('disputes.show', $dispute))->assertForbidden();

        $this->staff(UserRole::Moderator);
        $this->get(route('admin.disputes.index'))->assertOk()->assertSee($this->keyItem()->title);
        $this->assertSame(DisputeStatus::AwaitingSeller, $dispute->fresh()->status);
    }

    public function test_crypto_refund_through_a_dispute_is_booked_on_the_line_when_confirmed(): void
    {
        config(['services.shkeeper.payouts_enabled' => true, 'services.shkeeper.payout_username' => 'u', 'services.shkeeper.payout_password' => 'p', 'services.shkeeper.payout_fees' => ['BTC' => '10']]);
        Http::fake([
            'shkeeper.test/api/v1/crypto' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/shkeeper/crypto.json')), true)),
            'shkeeper.test/api/v1/BTC/quote' => Http::response(['crypto_amount' => '0.0006', 'exchange_rate' => '60000', 'status' => 'success']),
            'shkeeper.test/api/v1/BTC/payout' => Http::response(['task_id' => 't-1', 'external_id' => 'x']),
        ]);
        $dispute = $this->openDispute('refund');
        $this->staff(UserRole::Admin);
        $this->postForm(route('admin.disputes.resolve', $dispute), ['action' => 'refund', 'amount' => '25.00', 'method' => 'shkeeper', 'crypto' => 'BTC', 'destination' => 'bc1qbuyer'])
            ->assertSessionHas('success');

        $refund = Payment::query()->whereKey($dispute->fresh()->refund_payment_id)->firstOrFail();
        $this->assertSame(PaymentStatus::Pending, $refund->status);
        $this->assertSame(0, $this->keyItem()->refunded_minor);

        $raw = json_encode(['external_id' => 'refund-'.$refund->id, 'status' => 'SUCCESS', 'tx_hash' => 'tx-d']);
        $headers = ShkeeperClient::signatureHeaders($raw, 'test-webhook-secret', time());
        $this->call('POST', '/webhooks/shkeeper/payouts', [], [], [], $this->transformHeadersToServerVars($headers + ['Content-Type' => 'application/json', 'Accept' => 'application/json']), $raw)->assertStatus(202);

        $this->assertSame(2500, $this->keyItem()->refunded_minor);
        $this->assertSame(0, $this->order->items()->where('product_id', $this->otherSellerProduct->id)->value('refunded_minor'));
        $this->assertSame([], app(PayoutService::class)->reconcile());
    }
}
