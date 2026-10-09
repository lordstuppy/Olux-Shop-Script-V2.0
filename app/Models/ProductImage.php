<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImage extends Model
{
    protected $guarded = ['id'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function url(string $size = 'large'): string
    {
        return route('products.image', ['image' => $this->id, 'size' => $size === 'thumb' ? 'thumb' : 'large']);
    }
}
