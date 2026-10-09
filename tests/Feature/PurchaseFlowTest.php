<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Mail\OrderPaidMail;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PurchaseFlowTest extends TestCase
{
    public function test_register_login_checkout_webhook_marks_paid_and_delivers(): void
    {
        Mail::fake();
        $this->fakeShkeeper('9001');
        $product = $this->instantProductWithFile(['price_minor' => 1250, 'stock' => 10]);

        // Register (signs the user in).
        $this->postForm('/register', [
            'name' => 'Ada',
            'email' => 'ada@example.test',
            'password' => 'long-password-123',
            'password_confirmation' => 'long-password-123',
            'accept_terms' => '1',
            'form_token' => $this->formToken(),
        ])->assertRedirect(route('verification.notice'));
        User::where('email', 'ada@example.test')->update(['email_verified_at' => now()]);
        $this->postForm('/logout')->assertRedirect(route('home'));

        // Log in.
        $this->postForm('/login', ['email' => 'ada@example.test', 'password' => 'long-password-123'])
            ->assertRedirect(route('account.orders'));
        $this->assertAuthenticated();

        // Add to cart.
        $this->postForm('/cart/items', ['product_id' => $product->id, 'quantity' => 2])->assertRedirect(route('cart.show'));
        $this->get('/cart')->assertOk()->assertSee('25.00 USD');

        // Checkout with a client-supplied amount that must be ignored.
        $this->get('/checkout')->assertOk()->assertSee('Place order and pay 25.00 USD');
        $response = $this->postForm('/checkout', [
            'idempotency_key' => (string) Str::uuid(),
            'payment_method' => 'crypto',
            'crypto' => 'BTC',
            'accept_terms' => '1',
            'total_minor' => 1,
            'amount' => '0.01',
        ]);

        $order = Order::firstOrFail();
        $response->assertRedirect(route('orders.pay', $order));
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertSame(2500, $order->total_minor);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/v1/BTC/payment_request') && $request['amount'] === '25.00' && $request['external_id'] === $order->public_id);

        $payment = $order->payments()->firstOrFail();
        $this->assertSame('9001', $payment->provider_reference);
        $this->get(route('orders.pay', $order))->assertOk()->assertSee($payment->wallet_address)->assertSee('data:image/svg+xml;base64', false);

        // Visiting the result page does not mark anything paid.
        $this->get(route('orders.result', $order))->assertOk()->assertSee('waiting for payment confirmation');
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);

        // Shkeeper confirms the payment.
        $this->postShkeeperWebhook($this->paidPayload($order->public_id, '25.00'))->assertStatus(202)->assertJson(['status' => 'processed']);

        $order->refresh();
        $this->assertSame(OrderStatus::Delivered, $order->status);
        $this->assertNotNull($order->paid_at);
        $this->assertSame(PaymentStatus::Confirmed, $payment->fresh()->status);
        $item = $order->items()->firstOrFail();
        $this->assertNotNull($item->delivered_at);
        $this->assertSame('tool.zip', $item->delivered_payload['files'][0]['name']);
        $this->assertSame(8, $product->fresh()->stock);

        Mail::assertQueued(OrderPaidMail::class, fn ($mail) => $mail->hasTo('ada@example.test'));
        $invoice = Invoice::firstOrFail();
        Storage::disk('invoices')->assertExists($invoice->storage_path);

        $this->get(route('orders.result', $order))->assertSee("Order {$order->shortId()} paid. Download link sent to your email.");

        // The signed download link works for the owner.
        $page = $this->get(route('orders.show', $order))->assertOk();
        preg_match('/href="([^"]+\/files\/\d+[^"]+)"/', $page->getContent(), $m);
        $this->assertNotEmpty($m);
        $download = $this->get(html_entity_decode($m[1]));
        $download->assertOk();
        $this->assertStringContainsString('attachment', (string) $download->headers->get('Content-Disposition'));

        $this->get(route('orders.invoice', $order))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_double_submit_with_same_idempotency_key_creates_one_order(): void
    {
        $this->fakeShkeeper();
        $buyer = User::factory()->create();
        $product = Product::factory()->stock(5)->create();
        $this->actingAs($buyer);
        $this->postForm('/cart/items', ['product_id' => $product->id]);

        $data = ['idempotency_key' => (string) Str::uuid(), 'payment_method' => 'crypto', 'crypto' => 'BTC', 'accept_terms' => '1'];
        $first = $this->postForm('/checkout', $data);
        // The cart is cleared after the first submit; the replay still resolves to the same order.
        $second = $this->postForm('/checkout', $data);

        $this->assertSame(1, Order::count());
        $this->assertSame($first->headers->get('Location'), $second->headers->get('Location'));
        $this->assertSame(4, $product->fresh()->stock);
    }

    public function test_pay_with_balance(): void
    {
        $buyer = User::factory()->withBalance(5000)->create();
        $product = $this->instantProductWithFile(['price_minor' => 1999]);
        $this->actingAs($buyer);
        $this->postForm('/cart/items', ['product_id' => $product->id]);

        $this->postForm('/checkout', ['idempotency_key' => (string) Str::uuid(), 'payment_method' => 'balance', 'accept_terms' => '1'])
            ->assertRedirect();

        $order = Order::firstOrFail();
        $this->assertSame(OrderStatus::Delivered, $order->status);
        $this->assertSame(3001, $buyer->fresh()->balance_minor);
        $this->assertDatabaseHas('balance_transactions', ['user_id' => $buyer->id, 'amount_minor' => -1999, 'type' => 'order_payment']);
    }

    public function test_insufficient_balance_gives_specific_message(): void
    {
        $this->fakeShkeeper();
        $buyer = User::factory()->withBalance(1000)->create();
        $product = Product::factory()->price(2500)->create();
        $this->actingAs($buyer);
        $this->postForm('/cart/items', ['product_id' => $product->id]);

        $this->postForm('/checkout', ['idempotency_key' => (string) Str::uuid(), 'payment_method' => 'balance', 'accept_terms' => '1']);
        $order = Order::firstOrFail();

        $this->get(route('orders.pay', $order))->assertSee('Insufficient balance. Order total 25.00 USD, available 10.00 USD.');
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertSame(1000, $buyer->fresh()->balance_minor);
    }
}
