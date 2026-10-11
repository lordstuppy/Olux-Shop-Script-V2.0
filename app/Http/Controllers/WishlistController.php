<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\WishlistItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WishlistController extends Controller
{
    public function index(Request $request): View
    {
        $items = $request->user()->wishlistItems()->with('product.images', 'product.category')->latest('id')->get();
        // Listed products only (sold-out ones stay, shown as sold out).
        $listed = Product::query()->visible()->whereIn('id', $items->pluck('product_id'))->pluck('id')->all();
        $items = $items->filter(fn ($i) => in_array($i->product_id, $listed, true));

        return view('account.wishlist', ['items' => $items]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['product_id' => ['required', 'integer']]);
        $product = Product::query()->visible()->findOrFail($data['product_id']);
        if ($request->user()->wishlistItems()->count() >= 200) {
            return back()->with('error', __('Your wishlist is full (200 items). Remove some items first.'));
        }
        WishlistItem::query()->firstOrCreate(['user_id' => $request->user()->id, 'product_id' => $product->id]);

        return back()->with('success', __('":title" saved to your wishlist.', ['title' => $product->title]));
    }

    public function destroy(Request $request, Product $product): RedirectResponse
    {
        $removed = WishlistItem::query()->where('user_id', $request->user()->id)->where('product_id', $product->id)->delete();
        // Name the product only when it was on this wishlist (unlisted titles stay private).
        abort_if($removed === 0 && ! Product::query()->visible()->whereKey($product->id)->exists(), 404);

        return back()->with('success', __('":title" removed from your wishlist.', ['title' => $product->title]));
    }
}
