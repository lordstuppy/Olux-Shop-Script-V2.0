<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\CommissionService;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Support\Str;
use Tests\TestCase;

class CommissionTest extends TestCase
{
    private function sellerProduct(?int $sellerBps = null, ?int $categoryBps = null, ?int $productBps = null): Product
    {
        $seller = User::factory()->seller()->create();
        SellerProfile::create(['user_id' => $seller->id, 'display_name' => 'Shop '.$seller->id, 'status' => 'approved', 'payout_currency' => 'USD',
            'payout_crypto' => 'BTC', 'payout_address' => 'bc1qtestaddress00', 'commission_bps' => $sellerBps]);
        $category = Category::create(['name' => 'Cat '.Str::random(5), 'slug' => Str::random(8), 'commission_bps' => $categoryBps]);

        return $this->instantProductWithFile(['seller_id' => $seller->id, 'category_id' => $category->id, 'price_minor' => 10000, 'commission_bps' => $productBps]);
    }

    public function test_most_specific_level_wins(): void
    {
        config(['shop.commission_bps' => 1000]);
        $service = app(CommissionService::class);

        $this->assertSame(['bps' => 1000, 'source' => 'default'], $service->resolve($this->sellerProduct()));
        $this->assertSame(['bps' => 1500, 'source' => 'category'], $service->resolve($this->sellerProduct(categoryBps: 1500)));
        $this->assertSame(['bps' => 800, 'source' => 'seller'], $service->resolve($this->sellerProduct(sellerBps: 800, categoryBps: 1500)));
        $this->assertSame(['bps' => 0, 'source' => 'product'], $service->resolve($this->sellerProduct(sellerBps: 800, categoryBps: 1500, productBps: 0)));
    }

    public function test_rate_is_frozen_on_the_order_line_and_drives_the_ledger(): void
    {
        $product = $this->sellerProduct(categoryBps: 2000);
        $buyer = User::factory()->withBalance(10000)->create();
        $order = app(OrderService::class)->createFromCart($buyer, [$product->id => 1], 'USD', (string) Str::uuid());
        app(PaymentService::class)->payWithBalance($order, $buyer);

        $item = $order->items()->first();
        $this->assertSame(2000, $item->commission_bps);
        $this->assertDatabaseHas('seller_ledger_entries', ['order_item_id' => $item->id, 'type' => 'commission', 'amount_minor' => -2000]);
        $this->assertDatabaseHas('seller_ledger_entries', ['order_item_id' => $item->id, 'type' => 'sale', 'amount_minor' => 10000]);

        // Changing the category later does not touch the past sale.
        $product->category->update(['commission_bps' => 500]);
        $this->assertSame(2000, $item->fresh()->commission_bps);
    }

    public function test_super_admin_manages_rates_with_audit(): void
    {
        $product = $this->sellerProduct();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->confirmPassword();

        $this->get(route('admin.commission.index'))->assertOk()->assertSee('Global default:');
        $this->putForm(route('admin.commission.category', $product->category_id), ['commission_percent' => '12.5'])
            ->assertSessionHas('success', 'Commission for "'.$product->category->name.'" set to 12.50%.');
        $this->assertSame(1250, $product->category->fresh()->commission_bps);

        $profile = $product->seller->sellerProfile;
        $this->putForm(route('admin.commission.seller', $profile), ['commission_percent' => '7'])->assertSessionHas('success');
        $this->putForm(route('admin.commission.product'), ['product_id' => $product->id, 'commission_percent' => '3.25'])->assertSessionHas('success');
        $this->assertSame(325, $product->fresh()->commission_bps);
        $this->get(route('admin.products.show', $product->id))->assertSee('commission 3.25% (product override)');

        $this->putForm(route('admin.commission.product'), ['product_id' => $product->id, 'commission_percent' => ''])
            ->assertSessionHas('success', 'Commission for "'.$product->title.'" cleared; the next level applies.');
        $this->assertNull($product->fresh()->commission_bps);
        $this->assertSame(4, AuditLog::query()->where('action', 'commission.changed')->count());

        $this->putForm(route('admin.commission.product'), ['product_id' => $product->id, 'commission_percent' => '101'])->assertSessionHasErrors('commission_percent');
    }

    public function test_other_tiers_cannot_change_commission(): void
    {
        $product = $this->sellerProduct();
        foreach ([UserRole::Manager, UserRole::Finance, UserRole::Moderator] as $role) {
            $this->actingAs(User::factory()->staff($role)->create())->confirmPassword();
            $this->get(route('admin.commission.index'))->assertForbidden();
            $this->putForm(route('admin.commission.product'), ['product_id' => $product->id, 'commission_percent' => '1'])->assertForbidden();
        }
        $this->assertNull($product->fresh()->commission_bps);
    }

    public function test_manager_approving_a_seller_cannot_set_a_rate(): void
    {
        $applicant = User::factory()->create();
        $profile = SellerProfile::create(['user_id' => $applicant->id, 'display_name' => 'Shop', 'status' => 'pending', 'payout_currency' => 'USD', 'payout_crypto' => 'BTC', 'payout_address' => 'bc1qtestaddress00']);
        $this->actingAs(User::factory()->staff(UserRole::Manager)->create());
        $this->get(route('admin.sellers.index'))->assertOk()->assertDontSee('Commission override');
        $this->postForm(route('admin.sellers.approve', $profile), ['commission_bps' => 1])->assertSessionHas('success');
        $this->assertNull($profile->fresh()->commission_bps);
    }

    public function test_seller_sees_the_commission_on_their_products(): void
    {
        $product = $this->sellerProduct(categoryBps: 1500);
        $this->actingAs($product->seller)->get(route('seller.products.index'))->assertSee('15.00%');
    }
}
