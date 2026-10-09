<?php

namespace Tests\Feature;

use App\Enums\CouponType;
use App\Enums\OrderStatus;
use App\Enums\TicketCategory;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Admin\ExportController;
use App\Jobs\DeliverOrder;
use App\Mail\OrderDeliveredMail;
use App\Mail\TicketReplyMail;
use App\Models\Announcement;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\TicketService;
use App\Support\Settings;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminFeaturesTest extends TestCase
{
    private function paidOrder(Product $product, ?User $buyer = null): Order
    {
        $buyer ??= User::factory()->withBalance($product->price_minor)->create();
        $order = app(OrderService::class)->createFromCart($buyer, [$product->id => 1], 'USD', (string) Str::uuid());
        app(PaymentService::class)->payWithBalance($order, $buyer);

        return $order->fresh();
    }

    private function admin(UserRole $role = UserRole::Admin): User
    {
        $user = User::factory()->staff($role)->create();
        $this->actingAs($user)->confirmPassword();

        return $user;
    }

    public function test_order_actions(): void
    {
        Mail::fake();
        $this->admin();

        // Cancel a pending order.
        $pending = app(OrderService::class)->createFromCart(User::factory()->create(), [Product::factory()->stock(3)->create()->id => 2], 'USD', (string) Str::uuid());
        $this->postForm(route('admin.orders.cancel', $pending))->assertSessionHas('success');
        $this->assertSame(OrderStatus::Cancelled, $pending->fresh()->status);

        // Deliver a manual item on the seller's behalf.
        $order = $this->paidOrder(Product::factory()->manual()->price(1000)->create());
        $item = $order->items()->first();
        $this->postForm(route('admin.orders.items.deliver', [$order, $item]), ['payload' => 'Access: https://example.test/x'])->assertSessionHas('success');
        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
        Mail::assertQueued(OrderDeliveredMail::class);
        $this->assertSame('Access: https://example.test/x', $item->fresh()->delivered_payload['text']);

        // Regenerate the invoice; reset downloads.
        $this->postForm(route('admin.orders.invoice', $order))->assertSessionHas('success');
        Storage::disk('invoices')->assertExists(Invoice::where('order_id', $order->id)->value('storage_path'));
        $item->forceFill(['download_count' => 7])->save();
        $this->postForm(route('admin.orders.items.reset-downloads', [$order, $item]))->assertSessionHas('success', fn ($m) => str_contains($m, 'was 7'));
        $this->assertSame(0, $item->fresh()->download_count);

        Queue::fake();
        $this->postForm(route('admin.orders.redeliver', $order))->assertSessionHas('success');
        Queue::assertPushed(DeliverOrder::class, fn ($job) => $job->orderId === $order->id);
    }

    public function test_balance_adjustment(): void
    {
        $admin = $this->admin(UserRole::Finance);
        $user = User::factory()->create();

        $this->postForm(route('admin.users.balance', $user), ['direction' => 'credit', 'amount' => '12.50', 'currency' => 'USD', 'reason' => 'Goodwill'])->assertSessionHas('success');
        $this->assertSame(1250, $user->fresh()->balance_minor);
        $this->postForm(route('admin.users.balance', $user), ['direction' => 'debit', 'amount' => '20.00', 'currency' => 'USD', 'reason' => 'x'])
            ->assertSessionHas('error', 'Insufficient balance. Order total 20.00 USD, available 12.50 USD.');
        $this->assertDatabaseHas('balance_transactions', ['user_id' => $user->id, 'type' => 'admin_adjustment', 'note' => 'Adjustment by staff: Goodwill']);
        $this->assertDatabaseHas('audit_log', ['action' => 'balance.adjusted', 'actor_id' => $admin->id]);

        $this->admin(UserRole::Support);
        $this->postForm(route('admin.users.balance', $user), ['direction' => 'credit', 'amount' => '1', 'currency' => 'USD', 'reason' => 'x'])->assertForbidden();
    }

    public function test_csv_export_neutralises_formulas(): void
    {
        $this->admin(UserRole::Finance);
        $user = User::factory()->create();
        $this->postForm(route('admin.users.balance', $user), ['direction' => 'credit', 'amount' => '5', 'currency' => 'USD', 'reason' => '=HYPERLINK("http://evil")']);

        $response = $this->get(route('admin.exports.download', ['type' => 'balances', 'from' => now()->subDay()->toDateString(), 'to' => now()->toDateString()]));
        $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $csv = $response->streamedContent();
        $this->assertStringContainsString('id,user_email,type,currency,amount,balance_after,note,created_at', $csv);
        $this->assertStringContainsString('5.00', $csv);
        $this->assertStringNotContainsString(',=HYPERLINK', $csv);
        $this->assertStringContainsString('Adjustment by staff: =HYPERLINK', $csv);

        $this->assertSame("'=1+1", ExportController::cell('=1+1'));
        $this->assertSame('-12.50', ExportController::cell('-12.50'));

        $this->admin(UserRole::Support);
        $this->get(route('admin.exports.index'))->assertForbidden();
    }

    public function test_settings_override_config_and_are_audited(): void
    {
        $this->admin();
        $this->get(route('admin.settings.index'))->assertOk()->assertSee('Platform commission');

        $values = collect(Settings::EDITABLE)->mapWithKeys(fn ($d, $k) => [$k => config('shop.'.$k)])->all();
        $values['commission_bps'] = 1500;
        $values['max_open_orders'] = 0;
        $this->putForm(route('admin.settings.update'), $values)->assertSessionHasErrors('max_open_orders');

        $values['max_open_orders'] = 5;
        $this->putForm(route('admin.settings.update'), $values)->assertSessionHas('success', 'Saved 2 changed settings.');
        $this->assertSame(1500, config('shop.commission_bps'));
        $this->assertDatabaseHas('settings', ['key' => 'commission_bps', 'value' => '1500']);
        $this->assertDatabaseHas('audit_log', ['action' => 'settings.updated']);

        // New sales use the new commission.
        $order = $this->paidOrder(Product::factory()->price(10000)->create());
        $this->assertSame(1500, $order->items()->first()->commission_bps);

        $this->admin(UserRole::Finance);
        $this->get(route('admin.settings.index'))->assertForbidden();
    }

    public function test_ticket_assignment_and_internal_notes(): void
    {
        Mail::fake();
        $owner = User::factory()->create();
        $ticket = app(TicketService::class)->open($owner, 'Help', 'Body', TicketCategory::Support);

        $support = $this->admin(UserRole::Support);
        $this->postForm(route('tickets.reply', $ticket), ['body' => 'Customer is a reseller, check refunds.', 'internal' => '1'])->assertSessionHas('success');
        Mail::assertNothingQueued();
        $this->assertSame(TicketStatus::Open, $ticket->fresh()->status);
        $this->assertSame($support->id, $ticket->fresh()->assigned_to);

        $other = User::factory()->staff(UserRole::Support)->create();
        $this->postForm(route('tickets.assign', $ticket), ['assigned_to' => $other->id])->assertSessionHas('success');
        $this->assertSame($other->id, $ticket->fresh()->assigned_to);
        $this->postForm(route('tickets.assign', $ticket), ['assigned_to' => $owner->id])->assertSessionHas('error');
        $this->get(route('admin.tickets.index', ['assigned' => 'none']))->assertDontSee('#'.$ticket->id.'<');

        $this->postForm(route('tickets.reply', $ticket), ['body' => 'We are on it.'])->assertSessionHas('success');
        Mail::assertQueued(TicketReplyMail::class, 1);

        $this->actingAs($owner);
        $this->get(route('tickets.show', $ticket))->assertSee('We are on it.')->assertDontSee('check refunds')->assertDontSee('Internal note');
        $this->postForm(route('tickets.reply', $ticket), ['body' => 'x', 'internal' => '1'])->assertSessionHas('error', 'Only staff can add internal notes.');
        $this->postForm(route('tickets.assign', $ticket), ['assigned_to' => null])->assertForbidden();
    }

    public function test_reports_page_shows_chart_and_tables(): void
    {
        $this->paidOrder(Product::factory()->price(2500)->create(['title' => 'Report product']));
        $this->admin(UserRole::Finance);

        $this->get(route('admin.reports'))->assertOk()
            ->assertSee('<svg', false)->assertSee('Net revenue per day (USD)')
            ->assertSee('Report product')->assertSee('25.00 USD')
            ->assertSee('100.0% conversion');
    }

    public function test_announcements_are_shown_within_their_window(): void
    {
        $this->admin(UserRole::Support);
        $this->postForm(route('admin.announcements.store'), ['title' => 'Maintenance', 'body' => 'Sunday 02:00 UTC'])->assertSessionHas('success');
        $this->postForm(route('admin.announcements.store'), ['title' => 'Future sale', 'body' => 'Soon', 'starts_at' => now()->addDay()->toDateTimeString()]);

        // Check the banner markup, not page text (the flash message also names the announcement).
        $this->get('/')->assertSee('<strong>Maintenance</strong> Sunday 02:00 UTC', false)->assertDontSee('<strong>Future sale</strong>', false);
        $this->postForm(route('admin.announcements.toggle', Announcement::where('title', 'Maintenance')->first()));
        $this->get('/')->assertDontSee('<strong>Maintenance</strong>', false);
    }

    public function test_categories_can_be_edited_and_deleted_when_empty(): void
    {
        $this->admin();
        $used = Category::create(['slug' => 'tools', 'name' => 'Tools']);
        Product::factory()->create(['category_id' => $used->id]);
        $empty = Category::create(['slug' => 'old', 'name' => 'Old']);

        $this->putForm(route('admin.categories.update', $used->id), ['name' => 'Software tools', 'description' => 'Apps'])->assertSessionHas('success');
        $this->assertSame('Software tools', $used->fresh()->name);
        $this->assertSame('tools', $used->fresh()->slug);
        $this->deleteForm(route('admin.categories.destroy', $used->id))->assertSessionHas('error');
        $this->deleteForm(route('admin.categories.destroy', $empty->id))->assertSessionHas('success');
        $this->assertNull(Category::find($empty->id));
    }

    public function test_coupon_per_buyer_limit(): void
    {
        Coupon::create(['code' => 'ONCE', 'type' => CouponType::Percent, 'value' => 1000, 'max_per_user' => 1, 'active' => true]);
        $buyer = User::factory()->withBalance(10000)->create();
        $product = $this->instantProductWithFile(['price_minor' => 1000]);
        $orders = app(OrderService::class);

        $first = $orders->createFromCart($buyer, [$product->id => 1], 'USD', (string) Str::uuid(), 'once');
        $this->assertSame(100, $first->discount_minor);

        $this->expectExceptionMessage('You have already used coupon ONCE the maximum of 1 time.');
        $orders->createFromCart($buyer, [$product->id => 1], 'USD', (string) Str::uuid(), 'ONCE');
    }

    public function test_coupon_use_is_returned_when_order_is_cancelled(): void
    {
        Coupon::create(['code' => 'ONCE', 'type' => CouponType::Percent, 'value' => 1000, 'max_per_user' => 1, 'active' => true]);
        $buyer = User::factory()->create();
        $product = Product::factory()->create();
        $orders = app(OrderService::class);
        $order = $orders->createFromCart($buyer, [$product->id => 1], 'USD', (string) Str::uuid(), 'ONCE');
        $orders->cancel($order, $buyer);

        $again = $orders->createFromCart($buyer, [$product->id => 1], 'USD', (string) Str::uuid(), 'ONCE');
        $this->assertGreaterThan(0, $again->discount_minor);
    }

    public function test_create_admin_command(): void
    {
        $this->artisan('shop:create-admin', ['email' => 'Boss@Example.test'])
            ->expectsQuestion('Password (at least 12 characters, letters and numbers)', 'long-password-123')
            ->expectsQuestion('Repeat the password', 'long-password-123')
            ->assertSuccessful();

        $admin = User::where('email', 'boss@example.test')->firstOrFail();
        $this->assertSame(UserRole::Admin, $admin->role);
        $this->assertFalse($admin->hasTwoFactor());
        $this->actingAs($admin)->get('/admin')->assertRedirect(route('account.two-factor'));

        $this->artisan('shop:create-admin', ['email' => 'boss@example.test'])->assertFailed();
        $this->artisan('shop:create-admin', ['email' => 'x@example.test'])
            ->expectsQuestion('Password (at least 12 characters, letters and numbers)', 'short')
            ->assertFailed();
    }
}
