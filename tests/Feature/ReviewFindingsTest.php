<?php

namespace Tests\Feature;

use App\Enums\ProductStatus;
use App\Enums\UserRole;
use App\Mail\ProductDecisionMail;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PDOException;
use Tests\TestCase;

/** Regression tests for the findings of the independent review of the simulation fixes. */
class ReviewFindingsTest extends TestCase
{
    public function test_uploads_to_a_paused_product_send_it_to_review_so_resume_cannot_skip_it(): void
    {
        Queue::fake();
        $seller = User::factory()->seller()->create();
        $product = $this->instantProductWithFile(['seller_id' => $seller->id, 'status' => ProductStatus::Paused]);
        $this->actingAs($seller);

        $this->postForm(route('seller.products.files.store', $product->id), ['file' => UploadedFile::fake()->createWithContent('v2.zip', 'PK new content')])
            ->assertSessionHas('success');
        $this->assertSame(ProductStatus::PendingReview, $product->fresh()->status);
        $this->postForm(route('seller.products.resume', $product->id))->assertSessionHas('error');
        $this->assertSame(ProductStatus::PendingReview, $product->fresh()->status);
    }

    public function test_resume_refuses_files_that_are_not_scanned_clean(): void
    {
        $seller = User::factory()->seller()->create();
        $product = $this->instantProductWithFile(['seller_id' => $seller->id, 'status' => ProductStatus::Paused]);
        $product->files()->update(['scan_status' => 'pending']);
        $this->actingAs($seller)->postForm(route('seller.products.resume', $product->id))->assertSessionHas('error', fn ($m) => str_contains($m, 'not been scanned clean'));
        $this->assertSame(ProductStatus::Paused, $product->fresh()->status);
    }

    public function test_staff_cannot_relist_a_product_the_seller_paused(): void
    {
        $product = $this->instantProductWithFile(['status' => ProductStatus::Paused]);
        $this->actingAs(User::factory()->staff(UserRole::Moderator)->create());
        $this->get(route('admin.products.show', $product->id))->assertDontSee('Approve and list');
        $this->postForm(route('admin.products.status', $product->id), ['status' => 'active'])->assertSessionHas('error');
        $this->assertSame(ProductStatus::Paused, $product->fresh()->status);
    }

    public function test_array_inputs_never_cause_a_500(): void
    {
        $this->postForm('/register', ['name' => 'X', 'email' => ['a@b.c'], 'password' => 'long-password-123', 'password_confirmation' => 'long-password-123',
            'accept_terms' => '1', 'form_token' => $this->formToken()])->assertSessionHasErrors('email');
        $this->postForm('/login', ['email' => ['a@b.c'], 'password' => 'x'])->assertSessionHasErrors('email');
        $this->get('/products?q[]=x')->assertStatus(302);
        $this->get('/reset-password/token?email[]=x')->assertOk();
        $this->actingAs(User::factory()->create())->get('/tickets/new?order[]=x')->assertOk();
    }

    public function test_non_numeric_ids_are_404(): void
    {
        $this->actingAs(User::factory()->seller()->create());
        foreach (['/tickets/abc', '/disputes/abc', '/seller/products/abc/edit', '/product-images/abc/thumb'] as $path) {
            $this->get($path)->assertNotFound();
        }
    }

    public function test_database_starting_up_or_too_many_clients_is_a_503(): void
    {
        foreach (['SQLSTATE[57P03]: Cannot connect now: FATAL: the database system is starting up', 'SQLSTATE[53300] [7] FATAL: sorry, too many clients already'] as $i => $message) {
            Route::middleware('web')->get("/__db{$i}", fn () => throw new QueryException('pgsql', 'select 1', [], new PDOException($message)));
            $this->get("/__db{$i}")->assertStatus(503);
        }
    }

    public function test_buy_again_respects_the_cart_line_limit(): void
    {
        config(['shop.max_cart_lines' => 1]);
        $buyer = $this->buyer();
        $old = Product::factory()->create();
        $order = app(OrderService::class)->createFromCart($buyer, [$old->id => 1], 'USD', (string) Str::uuid());
        $order->update(['status' => 'cancelled']);
        $other = Product::factory()->create();
        $this->actingAs($buyer)->postForm('/cart/items', ['product_id' => $other->id]);

        $this->postForm(route('orders.reorder', $order))->assertSessionHas('error', fn ($m) => str_contains($m, 'cart is full'));
        $this->assertSame([$other->id => 1], session('cart.items'));
    }

    public function test_text_mails_show_quotes_as_typed(): void
    {
        $product = Product::factory()->create(['title' => 'Tom\'s "Pro" Kit']);
        (new ProductDecisionMail($product, false, 'Don\'t use "free" in the title.'))
            ->assertSeeInText('Tom\'s "Pro" Kit', false)->assertSeeInText('Don\'t use "free"', false)->assertDontSeeInText('&quot;', false);
    }
}
