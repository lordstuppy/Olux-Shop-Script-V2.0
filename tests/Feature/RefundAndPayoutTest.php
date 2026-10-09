<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentKind;
use App\Enums\PayoutStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\PayoutService;
use Tests\TestCase;

class RefundAndPayoutTest extends TestCase
{
    private function paidOrder(int $price = 5000, int $qty = 1, ?User $seller = null): Order
    {
        $seller ??= User::factory()->seller()->create();
        SellerProfile::firstOrCreate(['user_id' => $seller->id], ['display_name' => 'S', 'payout_currency' => 'USD', 'payout_address' => 'bc1qseller', 'status' => 'approved']);
        $product = $this->instantProductWithFile(['seller_id' => $seller->id, 'price_minor' => $price]);
        $buyer = User::factory()->withBalance($price * $qty)->create();
        $order = app(OrderService::class)->createFromCart($buyer, [$product->id => $qty], 'USD', (string) \Illuminate\Support\Str::uuid());
        app(PaymentService::class)->payWithBalance($order, $buyer);

        return $order->fresh();
    }

    public function test_partial_then_full_refund_are_separate_payment_rows(): void
    {
        $order = $this->paidOrder(5000);
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $this->postForm(route('admin.orders.refund', $order), ['amount' => '20.00', 'method' => 'balance', 'reason' => 'Partly broken'])
            ->assertRedirect()->assertSessionHas('success', 'Refunded 20.00 USD on order '.$order->shortId().' via balance.');
        $this->assertSame(OrderStatus::PartiallyRefunded, $order->fresh()->status);
        $this->assertSame(2000, $order->buyer->fresh()->balance_minor);

        $this->postForm(route('admin.orders.refund', $order), ['amount' => '40.00', 'method' => 'balance'])
            ->assertSessionHas('error', 'Refund amount 40.00 USD exceeds the refundable 30.00 USD for order '.$order->shortId().'.');

        $this->postForm(route('admin.orders.refund', $order), ['amount' => '30.00', 'method' => 'manual', 'reference' => 'txid-123'])->assertSessionHas('success');

        $order->refresh();
        $this->assertSame(OrderStatus::Refunded, $order->status);
        $this->assertSame(5000, $order->refunded_minor);
        $refunds = Payment::where('kind', PaymentKind::Refund->value)->orderBy('id')->get();
        $this->assertCount(2, $refunds);
        $this->assertSame([2000, 3000], $refunds->pluck('amount_minor')->all());
        $charge = Payment::where('kind', PaymentKind::Charge->value)->first();
        $this->assertSame([$charge->id, $charge->id], $refunds->pluck('parent_payment_id')->all());
        $this->assertSame(5000, $charge->fresh()->amount_minor);

        // Seller earnings reversed; ledger still reconciles.
        $this->assertSame(0, $order->items()->first()->seller_earning_minor);
        $this->assertSame([], app(PayoutService::class)->reconcile());
        $this->assertDatabaseHas('audit_log', ['action' => 'refund.created']);
    }

    public function test_payout_request_respects_hold_and_rejection_restores_balance(): void
    {
        $seller = User::factory()->seller()->create();
        $this->paidOrder(10000, 1, $seller);
        $this->actingAs($seller);

        // Earnings are on hold for the configured number of days.
        $this->postForm(route('seller.payouts.store'), ['amount' => '50.00', 'currency' => 'USD'])
            ->assertSessionHas('error', 'Requested 50.00 USD but only 0.00 USD is available for payout.');

        $this->travel(8)->days();
        $balances = app(PayoutService::class)->balances($seller);
        $this->assertSame(9000, $balances['USD']['available']);

        $this->postForm(route('seller.payouts.store'), ['amount' => '50.00', 'currency' => 'USD'])->assertSessionHas('success');
        $this->assertSame(4000, app(PayoutService::class)->balances($seller)['USD']['available']);

        $payout = Payout::firstOrFail();
        $this->actingAs(User::factory()->admin()->create());
        $this->postForm(route('admin.payouts.reject', $payout), ['note' => 'Address invalid'])->assertSessionHas('success');

        $this->assertSame(PayoutStatus::Rejected, $payout->fresh()->status);
        $this->assertSame(9000, app(PayoutService::class)->balances($seller)['USD']['available']);
        $this->assertSame([], app(PayoutService::class)->reconcile());
        $this->artisan('shop:reconcile-payouts')->assertSuccessful();
    }

    public function test_paid_payout_records_reference(): void
    {
        $seller = User::factory()->seller()->create();
        $this->paidOrder(10000, 1, $seller);
        $this->travel(8)->days();
        $payout = app(PayoutService::class)->requestPayout($seller, 9000, 'USD');

        $this->actingAs(User::factory()->admin()->create());
        $this->postForm(route('admin.payouts.paid', $payout), ['reference' => 'tx-abc'])->assertSessionHas('success');
        $this->assertSame('tx-abc', $payout->fresh()->reference);
        $this->assertSame(0, app(PayoutService::class)->balances($seller)['USD']['total']);
    }

    public function test_reconciliation_detects_tampering(): void
    {
        $order = $this->paidOrder(5000);
        $order->items()->update(['seller_earning_minor' => 9999]);

        $mismatches = app(PayoutService::class)->reconcile();
        $this->assertNotEmpty($mismatches);
        $this->artisan('shop:reconcile-payouts')->assertFailed();
    }
}
