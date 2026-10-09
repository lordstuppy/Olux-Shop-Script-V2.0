<?php

namespace App\Http\Controllers;

use App\Enums\ProductStatus;
use App\Models\ProductImage;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves product images. Images of listed products are public and cached;
 * images of unlisted products are visible only to their seller and staff.
 */
class ProductImageController extends Controller
{
    public function __invoke(ProductImage $image, string $size): StreamedResponse
    {
        $product = $image->product;
        $public = $product->status === ProductStatus::Active;
        if (! $public) {
            $user = auth()->user();
            abort_unless($user !== null && ($user->id === $product->seller_id || $user->can('products.manage')), 404);
        }

        $path = $size === 'thumb' ? $image->thumb_path : $image->path;

        return Storage::disk('product_images')->response($path, null, [
            'Content-Type' => 'image/webp',
            'Cache-Control' => $public ? 'public, max-age=31536000, immutable' : 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
