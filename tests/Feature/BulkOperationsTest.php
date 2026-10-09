<?php

namespace Tests\Feature;

use App\Enums\ProductStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BulkOperationsTest extends TestCase
{
    private function staff(UserRole $role = UserRole::Admin): User
    {
        $user = User::factory()->staff($role)->create();
        $this->actingAs($user)->confirmPassword();

        return $user;
    }

    public function test_bulk_approve_applies_the_same_checks_as_single_approval(): void
    {
        $ready = $this->instantProductWithFile(['status' => ProductStatus::PendingReview, 'title' => 'Ready one']);
        $alsoReady = $this->instantProductWithFile(['status' => ProductStatus::PendingReview, 'title' => 'Ready two']);
        $empty = Product::factory()->create(['status' => ProductStatus::PendingReview, 'title' => 'Empty one']);

        $this->staff(UserRole::Moderator);
        $this->get(route('admin.products.index'))->assertOk()->assertSee('form="bulk-products"', false)->assertSee('Bulk action');
        $this->postForm(route('admin.bulk.products'), ['action' => 'active', 'scope' => 'selected', 'ids' => [$ready->id, $alsoReady->id, $empty->id]])
            ->assertSessionHas('success', 'Bulk action finished: 2 products set to active, 1 skipped.')
            ->assertSessionHas('bulk_skipped', ['"Empty one" has nothing to deliver: it needs a file or licence keys before it can be approved.']);

        $this->assertSame(ProductStatus::Active, $ready->fresh()->status);
        $this->assertSame(ProductStatus::Active, $alsoReady->fresh()->status);
        $this->assertSame(ProductStatus::PendingReview, $empty->fresh()->status);
        $this->assertSame(2, DB::table('audit_log')->where('action', 'product.status_changed')->count());
        $this->assertDatabaseHas('audit_log', ['action' => 'bulk.product_status']);
    }

    public function test_bulk_status_by_filter(): void
    {
        $active = Product::factory()->count(3)->create();
        $draft = Product::factory()->create(['status' => ProductStatus::Draft]);

        $this->staff(UserRole::Manager);
        $this->postForm(route('admin.bulk.products'), ['action' => 'disabled', 'scope' => 'filtered', 'filter_status' => 'active'])
            ->assertSessionHas('success', 'Bulk action finished: 3 products set to disabled, 0 skipped.');
        $active->each(fn ($p) => $this->assertSame(ProductStatus::Disabled, $p->fresh()->status));
        $this->assertSame(ProductStatus::Draft, $draft->fresh()->status);

        $this->postForm(route('admin.bulk.products'), ['action' => 'active', 'scope' => 'selected', 'ids' => []])
            ->assertSessionHas('error', 'Nothing to change: tick at least one row, or choose "all rows matching the filter".');
        $this->postForm(route('admin.bulk.products'), ['action' => 'draft', 'scope' => 'selected', 'ids' => [$draft->id]])->assertSessionHasErrors('action');
    }

    public function test_bulk_suspend_users_skips_self_and_staff_for_managers(): void
    {
        $buyers = User::factory()->count(2)->create();
        $staffer = User::factory()->staff(UserRole::Support)->create();
        $manager = $this->staff(UserRole::Manager);

        $this->postForm(route('admin.bulk.users'), ['action' => 'suspended', 'scope' => 'selected', 'ids' => [...$buyers->pluck('id'), $staffer->id, $manager->id]])
            ->assertSessionHas('success', 'Bulk action finished: 2 accounts set to suspended, 2 skipped.')
            ->assertSessionHas('bulk_skipped', fn ($r) => in_array('You cannot suspend your own account.', $r, true)
                && in_array('Only a super admin can change the status of a staff account.', $r, true));
        $buyers->each(fn ($u) => $this->assertSame(UserStatus::Suspended, $u->fresh()->status));
        $this->assertSame(UserStatus::Active, $staffer->fresh()->status);

        $this->postForm(route('admin.bulk.users'), ['action' => 'active', 'scope' => 'filtered', 'filter_role' => 'buyer'])->assertSessionHas('success');
        $buyers->each(fn ($u) => $this->assertSame(UserStatus::Active, $u->fresh()->status));

        $this->staff(UserRole::Moderator);
        $this->postForm(route('admin.bulk.users'), ['action' => 'suspended', 'scope' => 'selected', 'ids' => [$buyers->first()->id]])->assertForbidden();
    }

    public function test_bulk_export_of_ticked_or_filtered_orders(): void
    {
        $product = $this->instantProductWithFile(['price_minor' => 1250]);
        $orders = collect(range(1, 3))->map(function () use ($product) {
            $buyer = User::factory()->withBalance(5000)->create();
            $order = app(OrderService::class)->createFromCart($buyer, [$product->id => 2], 'USD', (string) Str::uuid());
            app(PaymentService::class)->payWithBalance($order, $buyer);

            return $order->fresh();
        });
        $pending = app(OrderService::class)->createFromCart(User::factory()->create(), [$product->id => 1], 'USD', (string) Str::uuid());

        $this->staff(UserRole::Finance);
        $this->get(route('admin.orders.index'))->assertSee('form="bulk-orders"', false);
        $csv = $this->postForm(route('admin.bulk.orders.export'), ['scope' => 'selected', 'ids' => [$orders[0]->public_id, $orders[2]->public_id]])->assertOk();
        $this->assertStringContainsString('text/csv', $csv->headers->get('Content-Type'));
        $lines = array_values(array_filter(explode("\n", $csv->streamedContent())));
        $this->assertCount(3, $lines);
        $this->assertStringStartsWith('public_id,created_at,paid_at,status,buyer_email,currency,units', $lines[0]);
        $this->assertStringContainsString($orders[0]->public_id, $csv->streamedContent());
        $this->assertStringNotContainsString($orders[1]->public_id, $csv->streamedContent());
        $this->assertStringContainsString(',USD,2,25.00,0.00,25.00,0.00', $lines[1]);

        $filtered = $this->postForm(route('admin.bulk.orders.export'), ['scope' => 'filtered', 'filter_status' => 'pending'])->streamedContent();
        $this->assertStringContainsString($pending->public_id, $filtered);
        $this->assertStringNotContainsString($orders[0]->public_id, $filtered);
        $this->assertDatabaseHas('audit_log', ['action' => 'bulk.orders_exported']);

        $this->staff(UserRole::Support);
        $this->postForm(route('admin.bulk.orders.export'), ['scope' => 'filtered'])->assertForbidden();
    }
}
