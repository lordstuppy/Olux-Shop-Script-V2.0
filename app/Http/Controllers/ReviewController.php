<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\ReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function store(Request $request, string $slug, ReviewService $reviews): RedirectResponse
    {
        $product = Product::query()->visible()->where('slug', $slug)->firstOrFail();
        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:3000'],
        ]);
        $review = $reviews->submit($request->user(), $product, (int) $data['rating'], $data['title'], $data['body']);

        return redirect()->route('products.show', $product->slug)->withFragment('reviews')
            ->with('success', $review->wasRecentlyCreated ? 'Thank you, your review is published.' : 'Your review was updated.');
    }
}
