<?php

namespace App\Http\Controllers;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\WishlistItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WishlistController extends Controller
{
    public function index(Request $request): View
    {
        $items = $request->user()->wishlistItems()->with('product.images', 'product.category')->latest('id')->get()
            ->filter(fn ($i) => $i->product?->isPurchasable() || $i->product?->status === ProductStatus::Active);

        return view('account.wishlist', ['items' => $items]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['product_id' => ['required', 'integer']]);
        $product = Product::query()->visible()->findOrFail($data['product_id']);
        if ($request->user()->wishlistItems()->count() >= 200) {
            return back()->with('error', 'Your wishlist is full (200 items). Remove some items first.');
        }
        WishlistItem::query()->firstOrCreate(['user_id' => $request->user()->id, 'product_id' => $product->id]);

        return back()->with('success', "\"{$product->title}\" saved to your wishlist.");
    }

    public function destroy(Request $request, Product $product): RedirectResponse
    {
        WishlistItem::query()->where('user_id', $request->user()->id)->where('product_id', $product->id)->delete();

        return back()->with('success', "\"{$product->title}\" removed from your wishlist.");
    }
}
