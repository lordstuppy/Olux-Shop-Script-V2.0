<?php

namespace Tests\Feature;

use App\Enums\ProductStatus;
use App\Enums\UserRole;
use App\Mail\EmailChangeConfirmMail;
use App\Mail\EmailChangeNoticeMail;
use App\Mail\OrderPlacedMail;
use App\Mail\SellerApplicationMail;
use App\Mail\SellerSaleMail;
use App\Models\Category;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\CatalogService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\UserService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerFeaturesTest extends TestCase
{
    private function buy(Product $product, ?User $buyer = null): User
    {
        $buyer ??= User::factory()->withBalance($product->price_minor * 2)->create();
        $order = app(OrderService::class)->createFromCart($buyer, [$product->id => 1], 'USD', (string) Str::uuid());
        app(PaymentService::class)->payWithBalance($order, $buyer);

        return $buyer;
    }

    public function test_only_buyers_can_review_and_moderation_hides_reviews(): void
    {
        $product = $this->instantProductWithFile(['price_minor' => 1000, 'title' => 'Reviewed tool']);
        $this->actingAs(User::factory()->create());
        $this->get(route('products.show', $product->slug))->assertDontSee('Write a review');
        $this->postForm(route('products.reviews.store', $product->slug), ['rating' => 5, 'title' => 'x', 'body' => 'y'])
            ->assertSessionHas('error', 'Only buyers of "Reviewed tool" can review it.');

        $buyer = $this->buy($product);
        $this->actingAs($buyer);
        $this->get(route('products.show', $product->slug))->assertSee('Write a review');
        $this->postForm(route('products.reviews.store', $product->slug), ['rating' => 4, 'title' => 'Solid', 'body' => 'Does the job.'])->assertSessionHas('success');
        $this->postForm(route('products.reviews.store', $product->slug), ['rating' => 5, 'title' => 'Great', 'body' => 'Even better after the update.'])
            ->assertSessionHas('success', 'Your review was updated.');
        $this->assertSame(1, $product->reviews()->count());

        $this->get(route('products.show', $product->slug))
            ->assertSee('Rated 5.0 out of 5 (1 review)')->assertSee('Even better after the update.')
            ->assertSee('"aggregateRating":{"@type":"AggregateRating","ratingValue":5,"reviewCount":1}', false);

        $this->actingAs(User::factory()->staff(UserRole::Support)->create());
        $review = $product->reviews()->first();
        $this->postForm(route('admin.reviews.status', $review), ['status' => 'hidden'])->assertSessionHas('success');
        $this->get(route('products.show', $product->slug))->assertDontSee('Even better after the update.')->assertSee('No reviews yet.');
    }

    public function test_wishlist(): void
    {
        $product = Product::factory()->create(['title' => 'Wanted thing']);
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->postForm(route('wishlist.store'), ['product_id' => $product->id])->assertSessionHas('success');
        $this->postForm(route('wishlist.store'), ['product_id' => $product->id]);
        $this->assertSame(1, $user->wishlistItems()->count());
        $this->get(route('wishlist.index'))->assertSee('Wanted thing');
        $this->get(route('products.show', $product->slug))->assertSee('Remove from wishlist');

        $this->deleteForm(route('wishlist.destroy', $product->id))->assertSessionHas('success');
        $this->get(route('wishlist.index'))->assertSee('Your wishlist is empty');

        $hidden = Product::factory()->status(ProductStatus::Draft)->create();
        $this->postForm(route('wishlist.store'), ['product_id' => $hidden->id])->assertNotFound();
    }

    public function test_best_seller_sort_and_related_products(): void
    {
        $cat = Category::create(['slug' => 'tools', 'name' => 'Tools']);
        $quiet = Product::factory()->create(['title' => 'Quiet tool', 'category_id' => $cat->id]);
        $popular = $this->instantProductWithFile(['title' => 'Popular tool', 'category_id' => $cat->id, 'price_minor' => 500]);
        $this->buy($popular);
        $this->buy($popular);
        $other = Product::factory()->create(['title' => 'Unrelated']);

        $titles = collect(app(CatalogService::class)->paginate(['sort' => 'best'])->items())->pluck('title')->all();
        $this->assertSame('Popular tool', $titles[0]);

        $this->get(route('products.show', $quiet->slug))->assertSee('Related products')->assertSee('Popular tool')->assertDontSee('Unrelated');
        $this->get('/products?sort=best')->assertOk();
    }

    public function test_email_change_requires_confirmation_from_new_address(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'old@example.test']);
        User::factory()->create(['email' => 'taken@example.test']);
        $this->actingAs($user);

        $this->postForm(route('account.email'), ['email' => 'taken@example.test', 'current_password' => 'correct-horse-battery-1'])->assertSessionHas('error');
        $this->postForm(route('account.email'), ['email' => 'new@example.test', 'current_password' => 'wrong'])->assertSessionHasErrors('current_password');
        $this->postForm(route('account.email'), ['email' => 'new@example.test', 'current_password' => 'correct-horse-battery-1'])->assertSessionHas('success');
        $this->assertSame('old@example.test', $user->fresh()->email);

        $url = null;
        Mail::assertQueued(EmailChangeConfirmMail::class, function ($m) use (&$url) {
            $url = $m->confirmUrl;

            return $m->hasTo('new@example.test');
        });
        Mail::assertQueued(EmailChangeNoticeMail::class, fn ($m) => $m->hasTo('old@example.test'));

        $this->get(route('account.email.confirm', 'bogus'))->assertOk();
        $this->postForm(route('account.email.confirm', 'bogus'))->assertSessionHas('error');
        $this->get($url)->assertSee('new@example.test');
        $this->postForm($url)->assertRedirect(route('account.settings'));
        $this->assertSame('new@example.test', $user->fresh()->email);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->postForm($url)->assertSessionHas('error');
    }

    public function test_transactional_emails(): void
    {
        Mail::fake();
        // Order placed (crypto) -> awaiting payment email.
        $this->fakeShkeeper();
        $seller = User::factory()->seller()->create();
        $product = $this->instantProductWithFile(['seller_id' => $seller->id, 'price_minor' => 900]);
        $buyer = User::factory()->create();
        $order = app(OrderService::class)->createFromCart($buyer, [$product->id => 1], 'USD', (string) Str::uuid());
        app(PaymentService::class)->startShkeeperPayment($order, 'BTC');
        app(PaymentService::class)->startShkeeperPayment($order, 'LTC');
        Mail::assertQueued(OrderPlacedMail::class, 1);

        // Paid -> the seller hears about the sale.
        $this->postShkeeperWebhook($this->paidPayload($order->public_id, '9.00'));
        Mail::assertQueued(SellerSaleMail::class, fn ($m) => $m->hasTo($seller->email));

        // Seller application decision.
        $applicant = User::factory()->create();
        $profile = SellerProfile::create(['user_id' => $applicant->id, 'display_name' => 'A', 'payout_currency' => 'USD', 'payout_address' => 'bc1qapplicantaddr', 'status' => 'pending']);
        app(UserService::class)->rejectSeller($profile, User::factory()->admin()->create(), 'Missing details');
        Mail::assertQueued(SellerApplicationMail::class, fn ($m) => $m->hasTo($applicant->email));
    }
}
