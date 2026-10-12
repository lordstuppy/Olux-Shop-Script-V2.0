<?php

namespace Tests\Feature;

use App\Enums\ProductStatus;
use App\Enums\SellerProfileStatus;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\OrderService;
use App\Support\LivePages;
use Illuminate\Support\Str;
use Tests\TestCase;

/** UX audit (docs/ux): server side of the findings; the browser side is tests/browser/ux.spec.js. */
class UxAuditTest extends TestCase
{
    public function test_ux04_staff_decisions_change_the_seller_product_pages(): void
    {
        $seller = User::factory()->seller()->create();
        $product = Product::factory()->create(['seller_id' => $seller->id, 'status' => ProductStatus::PendingReview]);
        $list = LivePages::version($seller, '/seller/products');
        $edit = LivePages::version($seller, '/seller/products/'.$product->id.'/edit');
        $this->assertNotNull($list);
        $this->assertNotNull($edit);

        $this->travel(2)->seconds();
        $product->update(['status' => ProductStatus::Active]);

        $this->assertNotSame($list, LivePages::version($seller, '/seller/products'));
        $this->assertNotSame($edit, LivePages::version($seller, '/seller/products/'.$product->id.'/edit'));

        // Another seller learns nothing about this product.
        $other = User::factory()->seller()->create();
        $this->assertNull(LivePages::version($other, '/seller/products/'.$product->id.'/edit'));
        $this->assertNull(LivePages::version(User::factory()->create(), '/seller/products'));
    }

    public function test_ux04_the_application_page_follows_the_decision(): void
    {
        $user = User::factory()->create();
        $profile = SellerProfile::create(['user_id' => $user->id, 'display_name' => 'Applicant', 'payout_currency' => 'BTC',
            'payout_address' => 'bc1qexampleaddress000000000000000000000', 'status' => SellerProfileStatus::Pending]);
        $before = LivePages::version($user, '/sell');
        $this->assertNotNull($before);

        $this->travel(2)->seconds();
        $profile->update(['status' => SellerProfileStatus::Approved]);
        $user->update(['role' => UserRole::Seller]);

        $this->assertNotSame($before, LivePages::version($user->fresh(), '/sell'));
    }

    public function test_ux02_irreversible_actions_have_a_confirmation_step(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::create(['name' => 'Empty category', 'slug' => 'empty-category']);
        $html = $this->actingAs($admin)->get('/admin/categories')->assertOk()->getContent();

        // The delete form sits inside the closed confirmation panel, with the consequence spelled out.
        $this->assertMatchesRegularExpression('#<details class="confirm[^"]*"[^>]*>\s*<summary[^>]*>Delete<span class="visually-hidden"> Empty category</span></summary>\s*<div class="confirm-panel">\s*<p>[^<]*cannot be undone\.</p>\s*<form method="post" action="'.preg_quote(route('admin.categories.destroy', $category->id), '#').'"#', $html);
        $this->assertStringContainsString('Yes, delete category', $html);

        // The action itself is unchanged: one confirmed request deletes the category.
        $this->deleteForm(route('admin.categories.destroy', $category->id))->assertRedirect();
        $this->assertModelMissing($category);
    }

    public function test_ux01_table_boxes_can_take_keyboard_focus(): void
    {
        $seller = User::factory()->seller()->create();
        Product::factory()->create(['seller_id' => $seller->id]);
        $html = $this->actingAs($seller)->get('/seller/products')->assertOk()->getContent();

        $this->assertStringContainsString('<div class="table-wrap" tabindex="0">', $html);
    }

    public function test_ux08_order_status_sentence_has_no_stray_space(): void
    {
        $buyer = $this->buyer();
        $product = $this->instantProductWithFile(['price_minor' => 500]);
        $order = app(OrderService::class)->createFromCart($buyer, [$product->id => 1], 'USD', (string) Str::uuid());

        $html = $this->actingAs($buyer)->get('/orders/'.$order->public_id)->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression('#</span>\s+\.#', $html);
    }

    public function test_ux03_expired_form_page_explains_how_to_keep_the_input(): void
    {
        $this->post('/cart/items', ['product_id' => 1])
            ->assertStatus(419)
            ->assertSee('Nothing was changed.')
            ->assertSee('copy anything you typed')
            ->assertSee(route('login'), false);
    }

    public function test_ux07_catalogue_offers_clear_filters_when_filtered(): void
    {
        $this->instantProductWithFile(['title' => 'Font pack']);

        $this->get('/products?q=font')->assertOk()->assertSee('Clear filters');
        $this->get('/products')->assertOk()->assertDontSee('Clear filters');
    }
}
