<?php

namespace App\Services;

use App\Enums\ProductStatus;
use App\Exceptions\UserFacingException;
use App\Models\Product;
use App\Models\User;

/**
 * Staff status changes on products, used by the single and the bulk
 * actions so both apply the same checks: a product is only approved when
 * it has something to deliver and every current file scanned clean.
 */
class ProductModerationService
{
    /** Statuses staff may set. */
    public const STAFF_STATUSES = [ProductStatus::Active, ProductStatus::Disabled, ProductStatus::PendingReview];

    public function __construct(private readonly AuditLogger $audit) {}

    public function setStatus(Product $product, ProductStatus $status, User $actor): void
    {
        if (! in_array($status, self::STAFF_STATUSES, true)) {
            throw new UserFacingException(__('Products can be set to active, disabled or pending review.'));
        }
        if ($status === ProductStatus::Active) {
            if (! $product->hasDeliverableContent()) {
                throw new UserFacingException(__('":title" has nothing to deliver: it needs a file or licence keys before it can be approved.', ['title' => $product->title]));
            }
            $unscanned = $product->currentFiles()->whereNotIn('scan_status', ['clean', 'skipped'])->count();
            if ($unscanned > 0) {
                throw new UserFacingException(trans_choice('{1} ":title" has :count file without a clean virus scan. Approve it after the scan finishes.|[0,*] ":title" has :count files without a clean virus scan. Approve it after the scan finishes.', $unscanned, ['title' => $product->title]));
            }
        }
        $old = $product->status;
        if ($old === $status) {
            return;
        }
        $product->status = $status;
        $product->save();
        $this->audit->log('product.status_changed', $product, ['from' => $old->value, 'to' => $status->value], $actor);
    }
}
