<?php

namespace Tests\Feature;

use App\Enums\ProductStatus;
use App\Enums\SellerProfileStatus;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\GiftCard;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\OrderService;
use App\Support\LivePages;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

    public function test_u12_a_second_staff_decision_from_a_stale_page_is_refused_with_a_message(): void
    {
        $applicant = User::factory()->create();
        $profile = SellerProfile::create(['user_id' => $applicant->id, 'display_name' => 'Applicant', 'payout_currency' => 'BTC',
            'payout_address' => 'bc1qexampleaddress000000000000000000000', 'status' => SellerProfileStatus::Pending]);
        $first = User::factory()->admin()->create();
        $second = User::factory()->staff(UserRole::Manager)->create();

        // Both opened the list while the application was pending; the first approves.
        $this->actingAs($first)->postForm(route('admin.sellers.approve', $profile))->assertRedirect()->assertSessionHas('success');

        // The second, on the stale page, rejects: refused with an explanation, nothing changes.
        $response = $this->actingAs($second)->from('/admin/sellers')->postForm(route('admin.sellers.reject', $profile), ['note' => 'Incomplete']);
        $response->assertRedirect('/admin/sellers')->assertSessionHas('error');
        $this->assertSame(SellerProfileStatus::Approved, $profile->fresh()->status);
        $this->assertSame(UserRole::Seller, $applicant->fresh()->role);
    }

    public function test_ux15_the_live_response_carries_the_role_that_decides_the_menus(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->getJson('/live?path=/orders')->assertOk()->assertJson(['nav' => 'buyer']);
        $this->get('/orders')->assertSee('data-live-nav="buyer"', false);

        $user->forceFill(['role' => UserRole::Seller])->save();
        $this->getJson('/live?path=/orders')->assertJson(['nav' => 'seller']);
    }

    public function test_ux17_optional_fields_say_so(): void
    {
        $admin = User::factory()->admin()->create();
        $html = $this->actingAs($admin)->get('/admin/categories')->assertOk()->getContent();

        $this->assertStringContainsString('<label for="new-description">Description <span class="optional">(optional)</span></label>', $html);
        $this->assertStringContainsString('<label for="new-name">Name</label>', $html); // required fields stay unmarked
    }

    public function test_ux18_a_resent_balance_adjustment_is_applied_once(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create(['currency' => 'USD']);
        $this->actingAs($admin)->confirmPassword();
        $form = ['_once' => (string) Str::uuid(), 'direction' => 'credit', 'amount' => '5.00', 'currency' => 'USD', 'reason' => 'Goodwill'];

        $this->postForm(route('admin.users.balance', $user), $form)->assertSessionHas('success');
        $message = session('success');
        $this->postForm(route('admin.users.balance', $user), $form)->assertSessionHas('success', $message);

        $this->assertSame(500, $user->fresh()->balance_minor);
        $this->assertSame(1, DB::table('balance_transactions')->where('user_id', $user->id)->count());

        // A form without its key is refused, not processed.
        $this->postForm(route('admin.users.balance', $user), array_diff_key($form, ['_once' => true]))->assertSessionHas('error');
        $this->assertSame(500, $user->fresh()->balance_minor);
    }

    public function test_ux18_a_resent_gift_card_form_creates_one_card_and_shows_its_code_again(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->confirmPassword();
        $form = ['_once' => (string) Str::uuid(), 'amount' => '10.00', 'currency' => 'USD'];

        $this->postForm(route('admin.gift-cards.store'), $form)->assertSessionHas('success');
        $first = session('success');
        $this->assertMatchesRegularExpression('/Code: [A-Z0-9]{4}-/', $first);
        $this->postForm(route('admin.gift-cards.store'), $form)->assertSessionHas('success', $first);
        $this->assertSame(1, GiftCard::query()->count());

        // The kept message is encrypted at rest.
        $stored = Cache::get('submit-once:'.$admin->id.':'.$form['_once'].':result');
        $this->assertIsString($stored);
        $this->assertStringNotContainsString('Code:', $stored);
    }

    public function test_ux18_a_failed_submission_does_not_use_up_the_key(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->confirmPassword();
        $key = (string) Str::uuid();

        $this->postForm(route('admin.gift-cards.store'), ['_once' => $key, 'amount' => 'abc', 'currency' => 'USD'])->assertSessionHas('error');
        $this->postForm(route('admin.gift-cards.store'), ['_once' => $key, 'amount' => '10.00', 'currency' => 'USD'])->assertSessionHas('success');
        $this->assertSame(1, GiftCard::query()->count());
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
