<?php

namespace App\Services;

use App\Exceptions\UserFacingException;
use App\Models\Product;
use App\Models\ProductImage;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Product images are decoded and re-encoded as WebP with GD. Re-encoding
 * drops metadata (EXIF, GPS) and anything that is not pixel data, so a
 * crafted "image" cannot smuggle markup or scripts.
 */
class ProductImageService
{
    private const LARGE = 1600;

    private const THUMB = 480;

    private const MAX_PIXELS = 40000000;

    public function store(Product $product, UploadedFile $upload, ?string $alt): ProductImage
    {
        if ($product->images()->count() >= (int) config('shop.max_product_images')) {
            throw new UserFacingException(__('A product can have at most :max images. Remove one first.', ['max' => config('shop.max_product_images')]));
        }

        $info = @getimagesize($upload->getRealPath());
        if ($info === false || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw new UserFacingException(__('The image must be a JPEG, PNG or WebP file.'));
        }
        [$width, $height] = $info;
        if ($width < 1 || $height < 1 || $width * $height > self::MAX_PIXELS) {
            throw new UserFacingException(__('The image dimensions are not supported (at most 40 megapixels).'));
        }

        $source = @imagecreatefromstring((string) file_get_contents($upload->getRealPath()));
        if (! $source instanceof GdImage) {
            throw new UserFacingException(__('The image could not be read. Upload a different file.'));
        }

        $base = $product->id.'/'.Str::random(32);
        [$large, $lw, $lh] = $this->resize($source, self::LARGE);
        [$thumb] = $this->resize($source, self::THUMB);
        Storage::disk('product_images')->put($base.'.webp', $this->encode($large));
        Storage::disk('product_images')->put($base.'-thumb.webp', $this->encode($thumb));
        imagedestroy($source);

        return $product->images()->create([
            'path' => $base.'.webp',
            'thumb_path' => $base.'-thumb.webp',
            'width' => $lw,
            'height' => $lh,
            'alt_text' => $alt !== null && trim($alt) !== '' ? mb_substr(trim($alt), 0, 160) : null,
            'position' => (int) $product->images()->max('position') + 1,
        ]);
    }

    public function delete(ProductImage $image): void
    {
        Storage::disk('product_images')->delete([$image->path, $image->thumb_path]);
        $image->delete();
    }

    /** @return array{0: GdImage, 1: int, 2: int} */
    private function resize(GdImage $source, int $max): array
    {
        $w = imagesx($source);
        $h = imagesy($source);
        $scale = min(1, $max / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        $target = imagecreatetruecolor($nw, $nh);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagecopyresampled($target, $source, 0, 0, 0, 0, $nw, $nh, $w, $h);

        return [$target, $nw, $nh];
    }

    private function encode(GdImage $image): string
    {
        ob_start();
        imagewebp($image, null, 82);
        imagedestroy($image);

        return (string) ob_get_clean();
    }
}
