<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImage extends Model
{
    protected $guarded = ['id'];

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** $preview: the seller's and staff's URL, which also works while the product is not listed. */
    public function url(string $size = 'large', bool $preview = false): string
    {
        return route($preview ? 'products.image.preview' : 'products.image', ['image' => $this->id, 'size' => $size === 'thumb' ? 'thumb' : 'large']);
    }
}
