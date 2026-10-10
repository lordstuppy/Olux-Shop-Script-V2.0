<?php

namespace Tests\Feature;

use App\Enums\ProductStatus;
use App\Enums\UserRole;
use App\Mail\ProductDecisionMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ProductModerationNoteTest extends TestCase
{
    public function test_rejection_needs_a_reason_that_the_seller_sees_and_receives(): void
    {
        Mail::fake();
        $seller = User::factory()->seller()->create();
        $product = $this->instantProductWithFile(['seller_id' => $seller->id, 'status' => ProductStatus::PendingReview, 'title' => 'Needs Work']);
        $this->actingAs(User::factory()->staff(UserRole::Moderator)->create());

        $this->get(route('admin.products.show', $product->id))->assertSee('Reason for the seller')->assertSee('Reject');
        $this->postForm(route('admin.products.status', $product->id), ['status' => 'disabled'])
            ->assertSessionHasErrors(['note' => 'Tell the seller why the product is not for sale.']);
        $this->assertSame(ProductStatus::PendingReview, $product->fresh()->status);

        $this->postForm(route('admin.products.status', $product->id), ['status' => 'disabled', 'note' => 'Screenshots are missing.'])->assertSessionHas('success');
        $this->assertSame('Screenshots are missing.', $product->fresh()->moderation_note);
        Mail::assertQueued(ProductDecisionMail::class, fn ($m) => ! $m->approved && $m->note === 'Screenshots are missing.' && $m->hasTo($seller->email));

        $this->actingAs($seller)->get(route('seller.products.edit', $product->id))->assertSee('Not for sale. Reason from our team: Screenshots are missing.');
    }

    public function test_approval_clears_the_note_and_tells_the_seller(): void
    {
        Mail::fake();
        $seller = User::factory()->seller()->create();
        $product = $this->instantProductWithFile(['seller_id' => $seller->id, 'status' => ProductStatus::PendingReview, 'moderation_note' => 'Old reason']);
        $this->actingAs(User::factory()->staff(UserRole::Moderator)->create());

        $this->postForm(route('admin.products.status', $product->id), ['status' => 'active'])->assertSessionHas('success');
        $this->assertNull($product->fresh()->moderation_note);
        Mail::assertQueued(ProductDecisionMail::class, fn ($m) => $m->approved);
    }
}
