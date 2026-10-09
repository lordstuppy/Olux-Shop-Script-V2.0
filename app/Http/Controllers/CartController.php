<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\CartService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CartController extends Controller
{
    public function show(CartService $cart): View
    {
        $totals = $cart->totals();

        return view('cart.show', ['totals' => $totals, 'currencies' => Money::supported()]);
    }

    public function add(Request $request, CartService $cart): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:'.config('shop.max_quantity_per_line')],
        ]);
        $product = Product::query()->visible()->findOrFail($data['product_id']);
        $quantity = (int) ($data['quantity'] ?? 1);
        $cart->add($product, $quantity);

        return redirect()->route('cart.show')->with('success', "Added {$quantity} x \"{$product->title}\" to your cart.");
    }

    public function update(Request $request, Product $product, CartService $cart): RedirectResponse
    {
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:0', 'max:'.config('shop.max_quantity_per_line')],
        ]);
        $cart->update($product, (int) $data['quantity']);

        $message = (int) $data['quantity'] === 0
            ? "Removed \"{$product->title}\" from your cart."
            : "Updated \"{$product->title}\" to quantity {$data['quantity']}.";

        return redirect()->route('cart.show')->with('success', $message);
    }

    public function remove(Product $product, CartService $cart): RedirectResponse
    {
        $cart->remove($product->id);

        return redirect()->route('cart.show')->with('success', "Removed \"{$product->title}\" from your cart.");
    }

    public function currency(Request $request, CartService $cart): RedirectResponse
    {
        $data = $request->validate(['currency' => ['required', Rule::in(Money::supported())]]);
        $cart->setCurrency($data['currency']);

        return back()->with('success', "Prices are now shown in {$data['currency']}.");
    }
}
