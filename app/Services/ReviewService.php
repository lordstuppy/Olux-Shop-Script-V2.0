<?php

namespace App\Services;

use App\Exceptions\UserFacingException;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\User;

/**
 * Reviews from verified buyers only: the reviewer must have a paid order
 * item for the product that was not fully refunded. One review per buyer
 * and product; resubmitting edits it.
 */
class ReviewService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function eligibleItem(User $user, Product $product): ?OrderItem
    {
        return OrderItem::query()->where('product_id', $product->id)
            ->whereHas('order', fn ($q) => $q->where('buyer_id', $user->id)->whereIn('status', ['paid', 'delivered', 'partially_refunded']))
            ->latest('id')->first();
    }

    public function submit(User $user, Product $product, int $rating, string $title, string $body): ProductReview
    {
        $item = $this->eligibleItem($user, $product);
        if ($item === null) {
            throw new UserFacingException(__('Only buyers of ":title" can review it.', ['title' => $product->title]));
        }

        $review = ProductReview::query()->updateOrCreate(
            ['product_id' => $product->id, 'user_id' => $user->id],
            ['order_item_id' => $item->id, 'rating' => $rating, 'title' => $title, 'body' => $body],
        );
        $this->audit->log($review->wasRecentlyCreated ? 'review.created' : 'review.updated', $review, ['rating' => $rating], $user);

        return $review;
    }

    public function moderate(ProductReview $review, string $status, User $actor): void
    {
        $review->forceFill(['status' => $status, 'moderated_by' => $actor->id])->save();
        $this->audit->log('review.'.$status, $review, ['product_id' => $review->product_id], $actor);
    }

    /** @return array{average: ?float, count: int} */
    public function summary(Product $product): array
    {
        $row = ProductReview::query()->where('product_id', $product->id)->where('status', 'visible')
            ->selectRaw('AVG(rating) AS average, COUNT(*) AS count')->first();

        return ['average' => $row->count > 0 ? round((float) $row->average, 1) : null, 'count' => (int) $row->count];
    }
}
