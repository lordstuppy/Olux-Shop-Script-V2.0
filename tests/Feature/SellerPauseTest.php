<?php

namespace Tests\Feature;

use App\Enums\ProductStatus;
use App\Models\User;
use Tests\TestCase;

class SellerPauseTest extends TestCase
{
    public function test_seller_pauses_and_resumes_a_product_without_a_new_review(): void
    {
        $seller = User::factory()->seller()->create();
        $product = $this->instantProductWithFile(['seller_id' => $seller->id, 'title' => 'Pausable Tool']);
        $this->actingAs($seller);

        $this->postForm(route('seller.products.pause', $product->id))->assertSessionHas('success', '"Pausable Tool" is paused and no longer for sale.');
        $this->assertSame(ProductStatus::Paused, $product->fresh()->status);
        $this->get('/seller/products'); // shows the flash message once
        $this->get('/products')->assertDontSee('Pausable Tool');
        $this->get('/products/'.$product->slug)->assertNotFound();

        $this->postForm(route('seller.products.resume', $product->id))->assertSessionHas('success', '"Pausable Tool" is for sale again.');
        $this->assertSame(ProductStatus::Active, $product->fresh()->status);
        $this->get('/products')->assertSee('Pausable Tool');
    }

    public function test_editing_a_paused_product_sends_it_to_review_and_others_cannot_pause_it(): void
    {
        $seller = User::factory()->seller()->create();
        $product = $this->instantProductWithFile(['seller_id' => $seller->id, 'status' => ProductStatus::Paused, 'price_minor' => 1000]);

        $this->actingAs(User::factory()->seller()->create());
        $this->postForm(route('seller.products.resume', $product->id))->assertForbidden();
        $this->postForm(route('seller.products.pause', $product->id))->assertForbidden();

        $this->actingAs($seller);
        $this->putForm(route('seller.products.update', $product->id), [
            'title' => $product->title, 'description' => $product->description, 'price' => '12.00', 'currency' => 'USD', 'delivery_type' => 'instant',
        ])->assertSessionHas('success');
        $this->assertSame(ProductStatus::PendingReview, $product->fresh()->status);
        $this->postForm(route('seller.products.resume', $product->id))->assertSessionHas('error');
    }
}
