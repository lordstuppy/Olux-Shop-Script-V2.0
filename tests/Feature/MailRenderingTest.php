<?php

namespace Tests\Feature;

use App\Enums\PaymentProvider;
use App\Enums\PayoutStatus;
use App\Mail;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\SellerProfile;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Mail::fake() never renders templates, so every mailable is rendered here
 * once to catch broken views and missing variables.
 */
class MailRenderingTest extends TestCase
{
    public function test_every_mailable_renders(): void
    {
        $seller = User::factory()->seller()->create();
        $profile = SellerProfile::create(['user_id' => $seller->id, 'display_name' => 'Studio', 'payout_currency' => 'USD', 'payout_crypto' => 'BTC',
            'payout_address' => 'bc1qstudioaddress', 'status' => 'approved', 'pending_payout_address' => 'bc1qnewaddress', 'pending_payout_crypto' => 'BTC']);
        $product = $this->instantProductWithFile(['seller_id' => $seller->id, 'price_minor' => 1500, 'access_days' => 30]);
        $buyer = User::factory()->withBalance(1500)->create();
        $order = app(OrderService::class)->createFromCart($buyer, [$product->id => 1], 'USD', (string) Str::uuid());
        app(PaymentService::class)->payWithBalance($order, $buyer);
        $order = Order::with('items.product', 'buyer')->find($order->id);
        $charge = Payment::where('order_id', $order->id)->first();
        $refund = new Payment(['provider' => PaymentProvider::Balance, 'amount_minor' => 500, 'currency' => 'USD']);
        $payout = Payout::create(['seller_id' => $seller->id, 'amount_minor' => 1000, 'currency' => 'USD', 'destination' => 'bc1q', 'status' => PayoutStatus::Paid, 'reference' => 'tx']);
        $ticket = Ticket::create(['user_id' => $buyer->id, 'subject' => 'Help', 'status' => 'open', 'category' => 'support']);
        $device = UserDevice::create(['user_id' => $buyer->id, 'device_hash' => str_repeat('a', 64), 'ip' => '203.0.113.9', 'user_agent' => 'Test', 'last_seen_at' => now()]);

        $mailables = [
            new Mail\OrderPaidMail($order),
            new Mail\OrderDeliveredMail($order),
            new Mail\OrderPlacedMail($order, $charge),
            new Mail\TicketReplyMail($ticket),
            new Mail\NewDeviceLoginMail($buyer, $device),
            new Mail\SubscriptionRenewalMail($order->items->first()),
            new Mail\RefundIssuedMail($order, $refund),
            new Mail\PayoutStatusMail($payout),
            new Mail\PayoutAddressChangeMail($profile, 'https://shop.test/confirm'),
            new Mail\PayoutAddressChangedMail($profile),
            new Mail\EmailChangeConfirmMail($buyer, 'https://shop.test/email'),
            new Mail\EmailChangeNoticeMail($buyer, 'new@example.test'),
            new Mail\SellerApplicationMail($profile),
            new Mail\SellerSaleMail($order, $seller),
        ];

        foreach ($mailables as $mailable) {
            $text = $mailable->render();
            $this->assertNotSame('', trim($text), get_class($mailable));
            $this->assertDoesNotMatchRegularExpression('/[^\x09\x0A\x0D\x20-\x7E]/', $text, get_class($mailable).' must be ASCII');
        }
    }
}
