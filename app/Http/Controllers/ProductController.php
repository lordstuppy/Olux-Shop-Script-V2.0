<?php

namespace App\Http\Controllers;

use App\Services\CartService;
use App\Services\CatalogService;
use App\Services\CurrencyConverter;
use App\Services\ReviewService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function index(Request $request, CatalogService $catalog, CartService $cart): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:80'],
            'sort' => ['nullable', Rule::in(CatalogService::SORTS)],
            'currency' => ['nullable', Rule::in(Money::supported())],
            'seller' => ['nullable', 'integer', 'min:1'],
            'price_min' => ['nullable', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
            'price_max' => ['nullable', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
        ], [
            'price_min.regex' => __('Enter the minimum price as a number, for example 5 or 9.99.'),
            'price_max.regex' => __('Enter the maximum price as a number, for example 50 or 49.99.'),
        ]);
        if (isset($filters['price_min'], $filters['price_max']) && (float) $filters['price_min'] > (float) $filters['price_max']) {
            [$filters['price_min'], $filters['price_max']] = [$filters['price_max'], $filters['price_min']];
        }
        $filters['price_currency'] = $cart->currency();
        $sellers = $catalog->sellers();

        return view('products.index', [
            'products' => $catalog->paginate($filters),
            'categories' => $catalog->categories(),
            'sellers' => $sellers,
            'sellerName' => isset($filters['seller']) ? $sellers->get((int) $filters['seller']) : null,
            'filters' => $filters,
        ]);
    }

    public function show(string $slug, CatalogService $catalog, CartService $cart, CurrencyConverter $converter, ReviewService $reviews): View
    {
        $product = $catalog->findBySlug($slug);

        // Indicative price in the cart currency, if a rate exists.
        $cartCurrency = $cart->currency();
        $converted = null;
        if ($cartCurrency !== $product->currency && $converter->rate($product->currency, $cartCurrency) !== null) {
            $converted = $converter->convert($product->price_minor, $product->currency, $cartCurrency)[0];
        }

        $user = auth()->user();

        return view('products.show', [
            'product' => $product,
            'cartCurrency' => $cartCurrency,
            'convertedMinor' => $converted,
            'related' => $catalog->related($product),
            'reviews' => $product->visibleReviews()->with('user')->limit(20)->get(),
            'rating' => $reviews->summary($product),
            'canReview' => $user !== null && $reviews->eligibleItem($user, $product) !== null,
            'myReview' => $user?->id ? $product->reviews()->where('user_id', $user->id)->first() : null,
            'wishlisted' => $user !== null && $user->wishlistItems()->where('product_id', $product->id)->exists(),
        ]);
    }

    /** JSON for the optional live-search script. The page works without it. */
    public function suggest(Request $request, CatalogService $catalog): JsonResponse
    {
        $term = (string) $request->query('q', '');
        $results = $catalog->suggest(mb_substr($term, 0, 100))->map(fn ($p) => [
            'title' => $p->title,
            'url' => route('products.show', $p->slug),
        ]);

        return response()->json(['results' => $results]);
    }
}
