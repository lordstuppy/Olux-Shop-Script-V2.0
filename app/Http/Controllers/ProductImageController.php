<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves product images. Images of listed products are public and cached,
 * and their route runs without a session (no session read and write per
 * image, no cookies, so shared caches can keep them). Images of unlisted
 * products are visible only to their seller and staff, through preview().
 */
class ProductImageController extends Controller
{
    public function __invoke(ProductImage $image, string $size): StreamedResponse
    {
        // Same rule as the catalogue: active product of an active, selling seller.
        abort_unless(Product::query()->visible()->whereKey($image->product_id)->exists(), 404);

        return $this->send($image, $size, 'public, max-age=31536000, immutable');
    }

    /** Seller and staff view of any product's images (edit and moderation pages). */
    public function preview(ProductImage $image, string $size): StreamedResponse
    {
        $user = auth()->user();
        abort_unless($user !== null && ($user->id === $image->product->seller_id || $user->can('products.manage')), 404);

        return $this->send($image, $size, 'private, no-store');
    }

    private function send(ProductImage $image, string $size, string $cacheControl): StreamedResponse
    {
        $path = $size === 'thumb' ? $image->thumb_path : $image->path;

        return Storage::disk('product_images')->response($path, null, [
            'Content-Type' => 'image/webp',
            'Cache-Control' => $cacheControl,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
