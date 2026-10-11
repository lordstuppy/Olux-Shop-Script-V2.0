<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\DeliverOrder;
use App\Mail\ReviewReplyMail;
use App\Mail\SellerSaleMail;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Notifier;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\StaffAlerts;
use App\Services\TicketService;
use App\Support\LivePages;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationCentreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function paidOrder(User $buyer, Product $product): Order
    {
        $order = app(OrderService::class)->createFromCart($buyer, [$product->id => 1], 'USD', (string) Str::uuid());
        app(PaymentService::class)->payWithBalance($order, $buyer);
        DeliverOrder::dispatchSync($order->id);

        return $order->fresh();
    }

    private function badge(string $html): string
    {
        return (string) substr($html, (int) strpos($html, 'data-live-unread') - 40, 200);
    }

    public function test_events_land_in_the_notification_centre_with_a_badge_and_open_marks_them_read(): void
    {
        $seller = User::factory()->seller()->create(['notify_sales' => false]);
        $product = $this->instantProductWithFile(['seller_id' => $seller->id, 'price_minor' => 900]);
        $buyer = User::factory()->withBalance(900)->create();
        $order = $this->paidOrder($buyer, $product);

        // The seller turned sale emails off: no mail, but the sale is in the list.
        Mail::assertNotQueued(SellerSaleMail::class);
        $this->assertSame(1, UserNotification::where('user_id', $seller->id)->where('kind', 'sale')->count());

        $entry = UserNotification::where('user_id', $buyer->id)->where('kind', 'order')->latest('id')->firstOrFail();
        $this->assertStringContainsString($order->shortId(), $entry->title);
        $this->assertSame('/orders/'.$order->public_id, $entry->url);

        $unread = UserNotification::where('user_id', $buyer->id)->whereNull('read_at')->count();
        $html = $this->actingAs($buyer)->get('/')->getContent();
        $this->assertMatchesRegularExpression('/data-live-unread\s*>\s*<span data-live-unread-count>'.$unread.'<\/span>/', $html, $this->badge($html));
        $this->get(route('notifications.index'))->assertOk()->assertSee($entry->title)->assertSee('is-unread', false);

        $this->get(route('notifications.open', $entry->id))->assertRedirect('/orders/'.$order->public_id);
        $this->assertNotNull($entry->fresh()->read_at);

        $this->postForm(route('notifications.read-all'))->assertSessionHas('success');
        $this->assertSame(0, UserNotification::where('user_id', $buyer->id)->whereNull('read_at')->count());
        $html = $this->get('/')->getContent();
        $this->assertMatchesRegularExpression('/data-live-unread\s+hidden/', $html, $this->badge($html));

        // Someone else's entry is not found.
        $this->actingAs(User::factory()->create())->get(route('notifications.open', $entry->id))->assertNotFound();
    }

    public function test_entries_only_link_inside_the_shop(): void
    {
        $user = User::factory()->create();
        $notifier = app(Notifier::class);
        foreach (['https://evil.example/steal' => '/steal', '//evil.example/x' => '/x', '/\\evil.example' => '/evil.example', '/orders?x=1#a' => '/orders?x=1#a'] as $url => $stored) {
            $entry = $notifier->record($user, 'account', 'Test', $url);
            $this->assertSame($stored, $entry->url, $url);
            $location = $this->actingAs($user)->get(route('notifications.open', $entry->id))->headers->get('Location');
            $this->assertStringStartsWith(url('/'), $location);
        }
    }

    public function test_secret_links_stay_out_of_the_list(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->confirmPassword()->postForm(route('account.email'), ['email' => 'new@example.test', 'current_password' => 'password']);

        $this->assertSame(0, UserNotification::where('user_id', $user->id)->where('url', 'like', '%confirm%')->count());
    }

    public function test_staff_alerts_reach_the_list_of_super_admins_and_managers_only(): void
    {
        $admin = User::factory()->admin()->create();
        $manager = User::factory()->staff(UserRole::Manager)->create();
        $support = User::factory()->staff(UserRole::Support)->create();

        app(StaffAlerts::class)->send('Payout #9 failed in Shkeeper', ['Reason: no funds'], route('admin.payouts.index'));

        foreach ([$admin, $manager] as $staff) {
            $entry = UserNotification::where('user_id', $staff->id)->firstOrFail();
            $this->assertSame('staff', $entry->kind);
            $this->assertSame('/admin/payouts', $entry->url);
            $this->assertSame('Reason: no funds', $entry->body);
        }
        $this->assertSame(0, UserNotification::where('user_id', $support->id)->count());
    }

    public function test_sound_setting_and_old_entries_are_pruned(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/')->assertSee('data-live-sound="1"', false)->assertSee('data-live="'.route('live').'"', false);
        $this->putForm(route('account.notifications'), [])->assertSessionHas('success');
        $this->assertFalse($user->fresh()->notification_sound);
        $this->get('/')->assertSee('data-live-sound="0"', false);
        auth()->logout();
        $this->get('/')->assertDontSee('data-live=', false);

        $notifier = app(Notifier::class);
        $oldRead = $notifier->record($user, 'order', 'Old read');
        $oldRead->forceFill(['read_at' => now(), 'created_at' => now()->subDays(91)])->save();
        $oldUnread = $notifier->record($user, 'order', 'Old unread');
        $oldUnread->forceFill(['created_at' => now()->subDays(91)])->save();
        $ancient = $notifier->record($user, 'order', 'Ancient');
        $ancient->forceFill(['created_at' => now()->subDays(366)])->save();

        $this->artisan('shop:prune-notifications')->assertSuccessful();
        $this->assertSame(['Old unread'], UserNotification::where('user_id', $user->id)->pluck('title')->all());
    }

    public function test_live_endpoint_reports_unread_balance_and_the_page_version(): void
    {
        $seller = User::factory()->seller()->create();
        $product = $this->instantProductWithFile(['seller_id' => $seller->id, 'price_minor' => 700]);
        $buyer = User::factory()->withBalance(2000)->create();
        $order = app(OrderService::class)->createFromCart($buyer, [$product->id => 1], 'USD', (string) Str::uuid());
        $path = '/orders/'.$order->public_id;

        $this->getJson('/live?path='.urlencode($path))->assertUnauthorized();

        $this->actingAs($buyer);
        $before = $this->getJson('/live?path='.urlencode($path))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json();
        $this->assertSame('20.00 USD', $before['balance']);
        $this->assertNotNull($before['version']);
        $this->assertSame($before['version'], LivePages::version($buyer, $path));
        $this->get($path)->assertSee('data-live-version="'.$before['version'].'"', false);

        app(PaymentService::class)->payWithBalance($order, $buyer);
        DeliverOrder::dispatchSync($order->id);
        $after = $this->getJson('/live?path='.urlencode($path))->json();
        $this->assertNotSame($before['version'], $after['version'], 'paying and delivering must change the order page version');
        $this->assertSame('13.00 USD', $after['balance']);
        $this->assertGreaterThan(0, $after['unread']);
        $this->assertStringStartsWith(url('/notifications/'), $after['latest']['url']);

        // Pages the user may not see have no version, and say nothing about them.
        $this->actingAs(User::factory()->create());
        foreach ([$path, '/admin', '/seller/sales', '/no/such/page', '//evil.example', 'relative'] as $p) {
            $this->assertNull($this->getJson('/live?path='.urlencode($p))->json('version'), $p);
        }
        $this->getJson('/live?path[]=x')->assertOk()->assertJson(['version' => null]);
        $this->actingAs(User::factory()->admin()->create());
        $this->assertNotNull($this->getJson('/live?path=/admin')->json('version'));
    }

    public function test_internal_ticket_notes_do_not_change_the_customer_version(): void
    {
        $customer = User::factory()->create();
        $staff = User::factory()->staff(UserRole::Support)->create();
        $ticket = Ticket::create(['user_id' => $customer->id, 'subject' => 'Help', 'status' => 'open', 'category' => 'support']);
        $path = '/tickets/'.$ticket->id;
        $customerBefore = LivePages::version($customer, $path);
        $staffBefore = LivePages::version($staff, $path);

        $this->travel(2)->seconds();
        app(TicketService::class)->reply($ticket->fresh(), $staff, 'Looks like fraud.', internal: true);

        $this->assertSame($customerBefore, LivePages::version($customer, $path));
        $this->assertNotSame($staffBefore, LivePages::version($staff, $path));

        // A visible reply changes it.
        app(TicketService::class)->reply($ticket->fresh(), $staff, 'We are on it.');
        $this->assertNotSame($customerBefore, LivePages::version($customer, $path));
    }

    public function test_live_polling_is_rate_limited(): void
    {
        $this->actingAs(User::factory()->create());
        for ($i = 0; $i < 30; $i++) {
            $this->getJson('/live?path=/')->assertOk();
        }
        $this->getJson('/live?path=/')->assertStatus(429);
    }

    public function test_seller_replies_to_a_review_and_the_reviewer_is_told_once(): void
    {
        $seller = User::factory()->seller()->create();
        $product = $this->instantProductWithFile(['seller_id' => $seller->id, 'price_minor' => 500]);
        $buyer = User::factory()->withBalance(500)->create();
        $this->paidOrder($buyer, $product);
        $this->actingAs($buyer)->postForm(route('products.reviews.store', $product->slug), ['rating' => 3, 'title' => 'Okay', 'body' => 'Fine.']);
        $review = ProductReview::firstOrFail();

        $this->actingAs($seller)->get(route('seller.reviews'))->assertOk()->assertSee('Okay')->assertSee('Publish reply');
        $this->putForm(route('seller.reviews.reply', $review->id), ['reply' => 'Thanks! Version 2 is "faster".'])->assertSessionHas('success');
        Mail::assertQueued(ReviewReplyMail::class, fn ($mail) => $mail->hasTo($buyer->email));
        $entry = UserNotification::where('user_id', $buyer->id)->where('kind', 'review')->firstOrFail();
        $this->assertSame('/products/'.$product->slug.'#review-'.$review->id, $entry->url);

        $this->get(route('products.show', $product->slug))->assertSee('Reply from')->assertSee('Thanks! Version 2 is &quot;faster&quot;.', false);
        $this->putForm(route('seller.reviews.reply', $review->id), ['reply' => 'Edited reply.']);
        Mail::assertQueued(ReviewReplyMail::class, 1);
        $this->assertSame('Edited reply.', $review->fresh()->seller_reply);

        // Another seller cannot answer it; a hidden review cannot be answered.
        $this->actingAs(User::factory()->seller()->create())->putForm(route('seller.reviews.reply', $review->id), ['reply' => 'Spam'])
            ->assertSessionHas('error', 'You can only reply to reviews of your own products.');
        $this->actingAs(User::factory()->create())->putForm(route('seller.reviews.reply', $review->id), ['reply' => 'x'])->assertForbidden();

        $moderator = User::factory()->staff(UserRole::Moderator)->create();
        $this->actingAs($moderator)->get(route('admin.reviews.index'))->assertSee('Edited reply.');
        $this->deleteForm(route('admin.reviews.reply.destroy', $review))->assertSessionHas('success');
        $this->assertNull($review->fresh()->seller_reply);
        $this->assertDatabaseHas('audit_log', ['action' => 'review.reply_removed']);

        $review->forceFill(['status' => 'hidden'])->save();
        $this->actingAs($seller)->putForm(route('seller.reviews.reply', $review->id), ['reply' => 'Again'])
            ->assertSessionHas('error', 'This review was hidden by our team and cannot be answered.');

        // An empty reply removes it.
        $review->forceFill(['status' => 'visible', 'seller_reply' => 'x', 'seller_replied_at' => now()])->save();
        $this->putForm(route('seller.reviews.reply', $review->id), ['reply' => ''])->assertSessionHas('success', 'Your reply was removed.');
        $this->assertNull($review->fresh()->seller_reply);
    }
}
