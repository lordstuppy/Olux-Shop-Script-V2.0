<?php

namespace App\Services;

use App\Enums\DisputeReason;
use App\Enums\DisputeResolution;
use App\Enums\DisputeStatus;
use App\Enums\OrderStatus;
use App\Exceptions\UserFacingException;
use App\Mail\DisputeEscalatedMail;
use App\Mail\DisputeMessageMail;
use App\Mail\DisputeOpenedMail;
use App\Mail\DisputeResolvedMail;
use App\Models\Dispute;
use App\Models\DisputeMessage;
use App\Models\OrderItem;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Formal disputes on a purchased order line.
 *
 *  1. The buyer opens a case within the dispute window (from delivery, or
 *     from payment when nothing was delivered). The seller's earnings for
 *     the line are held from payouts while the case is open.
 *  2. The seller answers; the case then waits for staff. Without an answer
 *     by seller_respond_by, shop:escalate-disputes hands it to staff.
 *  3. Staff resolve it with a refund of that line only, a replacement
 *     (fresh licence keys, refreshed downloads or new delivery text), or a
 *     rejection with a reason. The buyer may withdraw an open case.
 * Every step is audited and emailed to the other parties.
 */
class DisputeService
{
    public function __construct(
        private readonly RefundService $refunds,
        private readonly ShkeeperPayoutService $shkeeper,
        private readonly DeliveryService $delivery,
        private readonly AuditLogger $audit,
        private readonly Notifier $notifier,
    ) {}

    /** The date until which the buyer can open a dispute for this line, or null when it is not disputable. */
    public function windowEndsAt(OrderItem $item): ?CarbonInterface
    {
        $order = $item->order;
        if (! in_array($order->status, [OrderStatus::Paid, OrderStatus::Delivered, OrderStatus::PartiallyRefunded], true)) {
            return null;
        }
        $from = $item->delivered_at ?? $order->paid_at;

        return $from?->copy()->addDays((int) config('shop.dispute_window_days'));
    }

    public function canOpen(OrderItem $item): bool
    {
        $until = $this->windowEndsAt($item);

        return $until !== null && $until->isFuture() && ! Dispute::query()->where('order_item_id', $item->id)->exists()
            && $item->netMinor() - $item->refunded_minor > 0;
    }

    public function open(OrderItem $item, User $buyer, DisputeReason $reason, string $outcome, string $body): Dispute
    {
        $order = $item->order;
        if ($order->buyer_id !== $buyer->id) {
            throw new UserFacingException(__('You can only open a dispute for your own purchases.'));
        }
        if (! in_array($outcome, ['refund', 'replacement'], true)) {
            throw new UserFacingException(__('Choose whether you want a refund or a replacement.'));
        }
        $until = $this->windowEndsAt($item);
        if ($until === null) {
            throw new UserFacingException(__('Order :order is :status, so it cannot be disputed.', ['order' => $order->shortId(), 'status' => mb_strtolower($order->status->label())]));
        }
        if ($until->isPast()) {
            throw new UserFacingException(__('The dispute window for ":title" closed on :date. Contact support if you still need help.', ['title' => $item->title, 'date' => $until->format('Y-m-d')]));
        }
        if ($item->netMinor() - $item->refunded_minor <= 0) {
            throw new UserFacingException(__('":title" was already refunded in full.', ['title' => $item->title]));
        }

        try {
            $dispute = DB::transaction(function () use ($item, $order, $buyer, $reason, $outcome, $body) {
                $dispute = Dispute::create([
                    'order_id' => $order->id,
                    'order_item_id' => $item->id,
                    'buyer_id' => $buyer->id,
                    'seller_id' => $item->seller_id,
                    'reason' => $reason,
                    'requested_outcome' => $outcome,
                    'status' => DisputeStatus::AwaitingSeller,
                    'seller_respond_by' => now()->addDays((int) config('shop.dispute_response_days')),
                ]);
                $this->message($dispute, $buyer, 'buyer', $body);
                $this->audit->log('dispute.opened', $dispute, ['order' => $order->public_id, 'item' => $item->id, 'reason' => $reason->value], $buyer);

                return $dispute;
            });
        } catch (UniqueConstraintViolationException) {
            throw new UserFacingException(__('A dispute for ":title" already exists.', ['title' => $item->title]));
        }

        Log::info('Dispute {dispute_id} opened for order {public_id}', ['dispute_id' => $dispute->id, 'public_id' => $order->public_id]);
        $this->notifier->send($dispute->buyer, new DisputeOpenedMail($dispute, 'buyer'));
        $this->notifier->send($dispute->seller, new DisputeOpenedMail($dispute, 'seller'));
        $this->alertStaff(new DisputeOpenedMail($dispute, 'staff'));

        return $dispute;
    }

    /** Buyer, seller or staff adds to the thread. A seller's first answer hands the case to staff. */
    public function reply(Dispute $dispute, User $author, string $body, bool $internal = false): DisputeMessage
    {
        $role = $this->roleOf($dispute, $author);
        if (! $dispute->isOpen()) {
            throw new UserFacingException(__('Dispute #:id is closed.', ['id' => $dispute->id]));
        }
        if ($internal && $role !== 'staff') {
            $internal = false;
        }

        $message = DB::transaction(function () use ($dispute, $author, $body, $internal, $role) {
            $locked = Dispute::query()->whereKey($dispute->id)->lockForUpdate()->firstOrFail();
            $message = $this->message($locked, $author, $role, $body, $internal);
            if ($role === 'seller' && $locked->status === DisputeStatus::AwaitingSeller) {
                $locked->forceFill(['status' => DisputeStatus::AwaitingStaff, 'seller_responded_at' => now()])->save();
                $this->audit->log('dispute.seller_responded', $locked, [], $author);
            }
            $dispute->setRawAttributes($locked->getAttributes(), true);

            return $message;
        });

        if (! $internal) {
            foreach ($this->otherParties($dispute, $role) as [$recipient, $recipientRole]) {
                $this->notifier->send($recipient, new DisputeMessageMail($dispute, $recipientRole, $role));
            }
            if ($role !== 'staff' && $dispute->status === DisputeStatus::AwaitingStaff) {
                $this->alertStaff(new DisputeMessageMail($dispute, 'staff', $role));
            }
        }

        return $message;
    }

    public function withdraw(Dispute $dispute, User $buyer): void
    {
        if ($dispute->buyer_id !== $buyer->id) {
            throw new UserFacingException(__('Only the buyer can withdraw a dispute.'));
        }
        $this->close($dispute, $buyer, DisputeStatus::Withdrawn, DisputeResolution::Withdrawn, __('The buyer withdrew the dispute.'));
    }

    /** Cases whose seller did not answer in time go to staff. */
    public function escalateOverdue(): int
    {
        $count = 0;
        // lazyById, not each(): each() pages with OFFSET, and escalating changes the
        // status this query filters on, so later pages would skip cases.
        Dispute::query()->with(['buyer', 'seller', 'item'])->where('status', DisputeStatus::AwaitingSeller->value)->where('seller_respond_by', '<', now())
            ->lazyById(200)->each(function (Dispute $dispute) use (&$count) {
                $escalated = DB::transaction(function () use ($dispute) {
                    $locked = Dispute::query()->whereKey($dispute->id)->lockForUpdate()->firstOrFail();
                    if ($locked->status !== DisputeStatus::AwaitingSeller) {
                        return false;
                    }
                    $locked->forceFill(['status' => DisputeStatus::AwaitingStaff, 'escalated_at' => now()])->save();
                    $this->message($locked, null, 'system', __('The seller did not respond in time. Our team will review the case.'));
                    $this->audit->log('dispute.escalated', $locked);
                    $dispute->setRawAttributes($locked->getAttributes(), true);

                    return true;
                });
                if ($escalated) {
                    $count++;
                    $this->notifier->send($dispute->buyer, new DisputeEscalatedMail($dispute, 'buyer'));
                    $this->notifier->send($dispute->seller, new DisputeEscalatedMail($dispute, 'seller'));
                    $this->alertStaff(new DisputeEscalatedMail($dispute, 'staff'));
                }
            });

        return $count;
    }

    /**
     * Refund of the disputed line. "balance" and "manual" are booked at once;
     * "shkeeper" sends crypto and is booked when Shkeeper confirms it.
     *
     * @param  array{method: string, reference?: ?string, crypto?: ?string, destination?: ?string}  $how
     */
    public function resolveWithRefund(Dispute $dispute, User $staff, int $amountMinor, array $how, string $note): void
    {
        // Moderators decide disputes, but moving money is a refund power
        // (orders.manage: admin, manager, finance), like on the order page.
        if (! $staff->can('orders.manage')) {
            throw new UserFacingException(__('Refunds need a manager or the finance team. Add an internal note asking them to refund this item.'));
        }
        $this->exclusively($dispute, fn () => $this->refundNow($dispute, $staff, $amountMinor, $how, $note));
    }

    /** @param  array{method: string, reference?: ?string, crypto?: ?string, destination?: ?string}  $how */
    private function refundNow(Dispute $dispute, User $staff, int $amountMinor, array $how, string $note): void
    {
        $this->assertResolvable($dispute, $staff);
        $order = $dispute->order;
        $item = $dispute->item;
        $reason = __('Dispute #:id', ['id' => $dispute->id]);
        $payment = $how['method'] === 'shkeeper'
            ? $this->shkeeper->sendRefund($order, $amountMinor, (string) ($how['crypto'] ?? ''), (string) ($how['destination'] ?? ''), $staff, $reason, $item)
            : $this->refunds->refund($order, $amountMinor, $how['method'], $staff, $how['reference'] ?? null, $reason, $item);

        $dispute->forceFill(['refund_minor' => $amountMinor, 'refund_payment_id' => $payment->id])->save();
        $text = $note !== '' ? $note : __('Refund of :amount issued.', ['amount' => Money::format($amountMinor, $order->currency)]);
        $this->close($dispute, $staff, DisputeStatus::Resolved, DisputeResolution::Refund, $text);
    }

    public function resolveWithReplacement(Dispute $dispute, User $staff, ?string $text, string $note): void
    {
        $this->exclusively($dispute, function () use ($dispute, $staff, $text, $note) {
            $this->assertResolvable($dispute, $staff);
            $summary = $this->delivery->replace($dispute->item, $text, $staff);
            $this->close($dispute, $staff, DisputeStatus::Resolved, DisputeResolution::Replacement, $note !== '' ? $note : $summary);
        });
    }

    public function reject(Dispute $dispute, User $staff, string $note): void
    {
        if (trim($note) === '') {
            throw new UserFacingException(__('Explain to the buyer why the dispute is rejected.'));
        }
        $this->exclusively($dispute, function () use ($dispute, $staff, $note) {
            $this->assertResolvable($dispute, $staff);
            $this->close($dispute, $staff, DisputeStatus::Resolved, DisputeResolution::Rejected, $note);
        });
    }

    /** Two staff members resolving the same case at once must not both refund or replace. */
    private function exclusively(Dispute $dispute, callable $work): void
    {
        $lock = Cache::lock('dispute-resolve:'.$dispute->id, 60);
        if (! $lock->get()) {
            throw new UserFacingException(__('Someone is resolving dispute #:id right now. Reload the page in a moment.', ['id' => $dispute->id]));
        }
        try {
            $work();
        } finally {
            $lock->release();
        }
    }

    /** Role of a user in this dispute: buyer, seller or staff; anyone else is refused. */
    public function roleOf(Dispute $dispute, User $user): string
    {
        return match (true) {
            $user->id === $dispute->buyer_id => 'buyer',
            $user->id === $dispute->seller_id => 'seller',
            $user->can('disputes.manage') => 'staff',
            default => throw new UserFacingException(__('You are not part of dispute #:id.', ['id' => $dispute->id])),
        };
    }

    private function assertResolvable(Dispute $dispute, ?User $staff = null): void
    {
        // Staff who are the buyer or the seller of the case never decide it.
        if ($staff !== null && in_array($staff->id, [$dispute->buyer_id, $dispute->seller_id], true)) {
            throw new UserFacingException(__('You are a party to dispute #:id, so another team member has to resolve it.', ['id' => $dispute->id]));
        }
        if (! $dispute->fresh()->isOpen()) {
            throw new UserFacingException(__('Dispute #:id is already closed.', ['id' => $dispute->id]));
        }
    }

    private function close(Dispute $dispute, User $actor, DisputeStatus $status, DisputeResolution $resolution, string $note): void
    {
        $closed = DB::transaction(function () use ($dispute, $actor, $status, $resolution, $note) {
            $locked = Dispute::query()->whereKey($dispute->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isOpen()) {
                return false;
            }
            $locked->forceFill([
                'status' => $status,
                'resolution' => $resolution,
                'resolution_note' => mb_substr($note, 0, 5000),
                'resolved_by' => $actor->id,
                'resolved_at' => now(),
            ])->save();
            $this->message($locked, $actor, $actor->id === $locked->buyer_id ? 'buyer' : 'staff', $resolution->label().': '.$note);
            $this->audit->log('dispute.'.$resolution->value, $locked, ['refund_minor' => $locked->refund_minor], $actor);
            $dispute->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
        if (! $closed) {
            throw new UserFacingException(__('Dispute #:id is already closed.', ['id' => $dispute->id]));
        }

        $this->notifier->send($dispute->buyer, new DisputeResolvedMail($dispute, 'buyer'));
        $this->notifier->send($dispute->seller, new DisputeResolvedMail($dispute, 'seller'));
    }

    private function message(Dispute $dispute, ?User $author, string $role, string $body, bool $internal = false): DisputeMessage
    {
        return $dispute->messages()->create([
            'author_id' => $author?->id,
            'author_role' => $role,
            'body' => mb_substr(trim($body), 0, 5000),
            'internal' => $internal,
        ]);
    }

    /** @return list<array{0: User, 1: string}> */
    private function otherParties(Dispute $dispute, string $role): array
    {
        return array_values(array_filter([
            $role !== 'buyer' ? [$dispute->buyer, 'buyer'] : null,
            $role !== 'seller' ? [$dispute->seller, 'seller'] : null,
        ]));
    }

    private function alertStaff(object $mail): void
    {
        $address = (string) config('shop.staff_alert_email');
        if ($address !== '') {
            Mail::to($address)->queue($mail);
        }
    }
}
