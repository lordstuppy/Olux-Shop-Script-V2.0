<?php

namespace App\Services;

use App\Exceptions\UserFacingException;
use App\Mail\ReviewReceivedMail;
use App\Mail\ReviewReplyMail;
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
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly Notifier $notifier,
    ) {}

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
        $seller = $product->seller;
        if ($review->wasRecentlyCreated && $seller !== null) {
            $this->notifier->send($seller, (new ReviewReceivedMail($review))->afterCommit(), email: $seller->notify_reviews);
        }

        return $review;
    }

    /**
     * The seller's public answer to a review (empty text removes it). Only the
     * first answer notifies the reviewer, so editing a reply sends nothing.
     */
    public function reply(ProductReview $review, User $seller, string $text): void
    {
        if ($review->product->seller_id !== $seller->id) {
            throw new UserFacingException(__('You can only reply to reviews of your own products.'));
        }
        if ($review->status !== 'visible') {
            throw new UserFacingException(__('This review was hidden by our team and cannot be answered.'));
        }
        $text = trim($text);
        $first = $review->seller_replied_at === null;
        if ($text === '') {
            $review->forceFill(['seller_reply' => null, 'seller_replied_at' => null])->save();
            $this->audit->log('review.reply_removed', $review, [], $seller);

            return;
        }
        $review->forceFill(['seller_reply' => mb_substr($text, 0, 2000), 'seller_replied_at' => now()])->save();
        $this->audit->log($first ? 'review.replied' : 'review.reply_edited', $review, [], $seller);
        if ($first) {
            $this->notifier->send($review->user, (new ReviewReplyMail($review))->afterCommit());
        }
    }

    /** Staff remove a seller reply that breaks the rules; the review stays. */
    public function removeReply(ProductReview $review, User $actor): void
    {
        $review->forceFill(['seller_reply' => null, 'seller_replied_at' => null])->save();
        $this->audit->log('review.reply_removed', $review, ['by' => 'staff'], $actor);
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
