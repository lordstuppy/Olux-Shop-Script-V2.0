<?php

namespace App\Http\Controllers;

use App\Models\Product;
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
        // Same rule as the catalogue: active product of an active, selling seller.
        $public = Product::query()->visible()->whereKey($product->id)->exists();
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
