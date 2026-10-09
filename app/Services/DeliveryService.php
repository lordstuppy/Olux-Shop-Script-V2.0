<?php

namespace App\Services;

use App\Enums\DeliveryType;
use App\Enums\OrderStatus;
use App\Exceptions\DeliveryException;
use App\Exceptions\UserFacingException;
use App\Mail\OrderDeliveredMail;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductFile;
use App\Models\ProductLicenseKey;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Writes order_items.delivered_payload for paid orders.
 *
 * Instant products deliver their files (as signed download links rendered
 * at view time) and, if the product has a licence key pool, one key per unit.
 * Manual products are fulfilled by the seller from the seller dashboard.
 */
class DeliveryService
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly AuditLogger $audit,
    ) {}

    public function deliver(Order $order): void
    {
        $items = $order->items()->with('product')->get();
        foreach ($items as $item) {
            if ($item->isDelivered() || $item->product->delivery_type === DeliveryType::Manual) {
                continue;
            }
            try {
                DB::transaction(fn () => $this->deliverInstant($item));
            } catch (DeliveryException $e) {
                Log::error('Failed to deliver product {product_id} for order {public_id}: {reason}', [
                    'product_id' => $item->product_id,
                    'public_id' => $order->public_id,
                    'reason' => $e->getMessage(),
                ]);
                $this->audit->log('delivery.failed', $item, ['reason' => $e->getMessage(), 'order' => $order->public_id]);
            }
        }

        $this->completeIfFullyDelivered($order);
    }

    /**
     * Delivery text written by the seller, or by staff on the seller's behalf
     * ($asStaff), for manual products or items whose automatic delivery failed.
     */
    public function deliverManually(OrderItem $item, User $seller, string $text, bool $asStaff = false): void
    {
        DB::transaction(function () use ($item, $seller, $text, $asStaff) {
            $locked = OrderItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $order = $locked->order;
            if (! $asStaff && $locked->seller_id !== $seller->id) {
                throw new UserFacingException(__('You can only deliver items you sold.'));
            }
            if (! in_array($order->status, [OrderStatus::Paid, OrderStatus::Delivered], true)) {
                throw new UserFacingException(__('Order :order is :status; it cannot be delivered.', ['order' => $order->shortId(), 'status' => mb_strtolower($order->status->label())]));
            }
            if ($locked->isDelivered()) {
                throw new UserFacingException(__('":title" in order :order was already delivered.', ['title' => $locked->title, 'order' => $order->shortId()]));
            }

            $locked->delivered_payload = ['type' => 'manual', 'text' => $text];
            $locked->delivered_at = now();
            $locked->access_expires_at = $this->accessExpiry($locked);
            $locked->save();
            $this->audit->log('delivery.manual', $locked, ['order' => $order->public_id], $seller);
            Log::info('Seller {seller_id} delivered item {item_id} of order {public_id}', [
                'seller_id' => $seller->id,
                'item_id' => $locked->id,
                'public_id' => $order->public_id,
            ]);
        });

        $order = $item->order()->first();
        $this->completeIfFullyDelivered($order);
        Mail::to($order->buyer)->queue((new OrderDeliveredMail($order))->afterCommit());
    }

    /**
     * Signed, expiring link to a file of a delivered item. The download
     * controller additionally checks that the signed-in user owns the order.
     */
    public function downloadUrl(Order $order, OrderItem $item, ProductFile $file, ?int $ttlMinutes = null): string
    {
        return URL::temporarySignedRoute(
            'orders.download',
            now()->addMinutes($ttlMinutes ?? (int) config('shop.download_link_ttl_minutes')),
            ['order' => $order->public_id, 'item' => $item->id, 'file' => $file->id],
        );
    }

    private function deliverInstant(OrderItem $item): void
    {
        $locked = OrderItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
        if ($locked->isDelivered()) {
            return;
        }

        $product = $locked->product()->with('activeFiles')->first();
        $payload = ['type' => 'instant', 'files' => [], 'license_keys' => []];

        foreach ($product->activeFiles as $file) {
            $payload['files'][] = ['id' => $file->id, 'name' => $file->original_name, 'size' => $file->size];
        }

        if ($product->licenseKeys()->exists()) {
            $keys = ProductLicenseKey::query()->where('product_id', $product->id)->whereNull('order_item_id')
                ->orderBy('id')->limit($locked->quantity)->lock('FOR UPDATE SKIP LOCKED')->get();
            if ($keys->count() < $locked->quantity) {
                throw new DeliveryException("only {$keys->count()} of {$locked->quantity} licence keys available");
            }
            foreach ($keys as $key) {
                $key->order_item_id = $locked->id;
                $key->assigned_at = now();
                $key->save();
                $payload['license_keys'][] = $key->key_encrypted;
            }
        }

        if ($payload['files'] === [] && $payload['license_keys'] === []) {
            throw new DeliveryException('product has no files or licence keys to deliver');
        }

        $locked->delivered_payload = $payload;
        $locked->delivered_at = now();
        $locked->access_expires_at = $this->accessExpiry($locked);
        $locked->save();
        $item->setRawAttributes($locked->getAttributes(), true);
    }

    /**
     * Replacement after a dispute: fresh licence keys from stock (the old ones
     * stay assigned and are recorded as replaced), the current product files
     * with the download counter reset, and/or new delivery text. Also
     * completes a line that was never delivered. Returns a summary.
     */
    public function replace(OrderItem $item, ?string $text, User $actor): string
    {
        $text = $text !== null && trim($text) !== '' ? trim($text) : null;
        $done = DB::transaction(function () use ($item, $text, $actor) {
            $locked = OrderItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $order = $locked->order;
            if (! in_array($order->status, [OrderStatus::Paid, OrderStatus::Delivered, OrderStatus::PartiallyRefunded], true)) {
                throw new UserFacingException(__('Order :order is :status; it cannot be delivered.', ['order' => $order->shortId(), 'status' => mb_strtolower($order->status->label())]));
            }
            $product = $locked->product()->with('activeFiles')->first();
            $payload = $locked->delivered_payload ?? ['type' => $product->delivery_type->value];
            $done = [];

            if ($product->delivery_type === DeliveryType::Instant) {
                if ($product->licenseKeys()->exists()) {
                    $keys = ProductLicenseKey::query()->where('product_id', $product->id)->whereNull('order_item_id')
                        ->orderBy('id')->limit($locked->quantity)->lock('FOR UPDATE SKIP LOCKED')->get();
                    if ($keys->count() < $locked->quantity) {
                        throw new UserFacingException(__('Only :count unused licence keys are left for ":title". Ask the seller to add keys, then send the replacement.', ['count' => $keys->count(), 'title' => $product->title]));
                    }
                    $payload['replaced_license_keys'] = array_merge($payload['replaced_license_keys'] ?? [], $payload['license_keys'] ?? []);
                    $payload['license_keys'] = [];
                    foreach ($keys as $key) {
                        $key->forceFill(['order_item_id' => $locked->id, 'assigned_at' => now()])->save();
                        $payload['license_keys'][] = $key->key_encrypted;
                    }
                    $done[] = trans_choice('{1} :count new licence key|[0,*] :count new licence keys', $keys->count());
                }
                $payload['files'] = $product->activeFiles->map(fn ($f) => ['id' => $f->id, 'name' => $f->original_name, 'size' => $f->size])->all();
                if ($payload['files'] !== []) {
                    $locked->download_count = 0;
                    $done[] = __('fresh download links');
                }
                if ($text !== null) {
                    $payload['note'] = $text;
                    $done[] = __('a note');
                }
            } else {
                if ($text === null) {
                    throw new UserFacingException(__('Enter the replacement delivery details for ":title".', ['title' => $locked->title]));
                }
                $payload = ['type' => 'manual', 'text' => $text];
                $done[] = __('new delivery details');
            }
            if ($done === []) {
                throw new UserFacingException(__('":title" has no files or licence keys to replace. Add a note with the replacement details.', ['title' => $locked->title]));
            }

            $locked->delivered_payload = $payload;
            $locked->access_expires_at ??= $this->accessExpiry($locked);
            $locked->delivered_at ??= now();
            $locked->save();
            $this->audit->log('delivery.replaced', $locked, ['order' => $order->public_id, 'what' => $done], $actor);
            $item->setRawAttributes($locked->getAttributes(), true);

            return $done;
        });

        $this->completeIfFullyDelivered($item->order()->first());
        Mail::to($item->order->buyer)->queue((new OrderDeliveredMail($item->order))->afterCommit());

        return __('Replacement delivered: :what.', ['what' => implode(', ', $done)]);
    }

    /** Subscriptions grant access for access_days per unit bought, from delivery. */
    private function accessExpiry(OrderItem $item): ?Carbon
    {
        $days = $item->product()->value('access_days');

        return $days === null ? null : now()->addDays((int) $days * $item->quantity);
    }

    private function completeIfFullyDelivered(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== OrderStatus::Paid) {
                return;
            }
            if ($locked->items()->whereNull('delivered_at')->exists()) {
                return;
            }
            $this->orders->transition($locked, OrderStatus::Delivered);
            $order->setRawAttributes($locked->getAttributes(), true);
        });
    }
}
