<?php

namespace App\Services;

use App\Enums\ProductStatus;
use App\Exceptions\UserFacingException;
use App\Mail\ProductDecisionMail;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

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

    /**
     * Sets a staff status. $note explains a disable to the seller (stored and
     * emailed); approving clears it. Bulk actions pass no note.
     */
    public function setStatus(Product $product, ProductStatus $status, User $actor, ?string $note = null): void
    {
        if (! in_array($status, self::STAFF_STATUSES, true)) {
            throw new UserFacingException(__('Products can be set to active, disabled or pending review.'));
        }
        if ($status === ProductStatus::Active && $product->status === ProductStatus::Paused) {
            throw new UserFacingException(__('":title" was paused by the seller; only the seller can put it back on sale.', ['title' => $product->title]));
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
        $note = $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 500) : null;
        $old = $product->status;
        if ($old === $status && ($status !== ProductStatus::Disabled || $note === null || $note === $product->moderation_note)) {
            return;
        }
        $product->status = $status;
        $product->moderation_note = match ($status) {
            ProductStatus::Active => null,
            ProductStatus::Disabled => $note ?? __('Disabled by our team. Contact support for details.'),
            default => $product->moderation_note,
        };
        $product->save();
        $this->audit->log('product.status_changed', $product, ['from' => $old->value, 'to' => $status->value, 'note' => $note], $actor);

        $seller = $product->seller;
        if ($seller !== null && ($status === ProductStatus::Disabled || ($status === ProductStatus::Active && $old === ProductStatus::PendingReview))) {
            Mail::to($seller)->queue((new ProductDecisionMail($product, $status === ProductStatus::Active, (string) $product->moderation_note))->afterCommit());
        }
    }
}
