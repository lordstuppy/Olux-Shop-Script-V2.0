<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductFile;
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

        Log::info('Download of file {file_id} for order {public_id} by user {user_id}', [
            'file_id' => $file->id,
            'public_id' => $order->public_id,
            'user_id' => auth()->id(),
        ]);

        return Storage::disk('products')->download($file->storage_path, $file->original_name);
    }
}
