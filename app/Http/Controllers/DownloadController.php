<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves product files. The route is signed and expiring, and on top of that
 * the signed-in user must own the order and the item must have been delivered
 * with this file. Files are never reachable by a public URL.
 */
class DownloadController extends Controller
{
    public function __invoke(Order $order, OrderItem $item, ProductFile $file): StreamedResponse
    {
        Gate::authorize('act', $order);

        abort_unless($item->order_id === $order->id, 404);
        abort_unless($file->product_id === $item->product_id, 404);
        abort_unless(in_array($order->status, [OrderStatus::Paid, OrderStatus::Delivered, OrderStatus::PartiallyRefunded], true), 403, 'Downloads are not available for this order.');
        $delivered = collect($item->delivered_payload['files'] ?? [])->pluck('id')->all();
        abort_unless(in_array($file->id, $delivered, true), 403, 'This file has not been delivered for this order.');
        abort_if($item->accessExpired(), 403, 'Access to this subscription ended on '.$item->access_expires_at?->format('Y-m-d').'. Renew it from the product page.');
        abort_if($file->scan_status === 'infected', 410, 'This file was removed because it failed a security scan.');

        // Count the download under a row lock so parallel requests cannot exceed the limit.
        $limit = $item->product->downloadLimit();
        $allowed = DB::transaction(function () use ($item, $limit) {
            $locked = OrderItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            if ($locked->download_count >= $limit) {
                return false;
            }
            $locked->increment('download_count');

            return true;
        });
        abort_unless($allowed, 403, "The download limit of {$limit} for this item has been reached. Open a support ticket if you need another copy.");

        Log::info('Download of file {file_id} for order {public_id} by user {user_id}', [
            'file_id' => $file->id,
            'public_id' => $order->public_id,
            'user_id' => auth()->id(),
        ]);

        return Storage::disk('products')->download($file->storage_path, $file->original_name);
    }
}
