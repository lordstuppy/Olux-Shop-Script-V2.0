<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function index(Request $request): View
    {
        $data = $request->validate(['status' => ['nullable', Rule::enum(ProductStatus::class)]]);
        $query = Product::with(['seller', 'category'])->withCount(['files', 'licenseKeys'])->latest('updated_at');
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        return view('admin.products', ['products' => $query->paginate(30)->withQueryString(), 'filters' => $data]);
    }

    public function status(Request $request, Product $product, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in([ProductStatus::Active->value, ProductStatus::Disabled->value])]]);
        $old = $product->status;
        $product->status = ProductStatus::from($data['status']);
        $product->save();
        $audit->log('product.status_changed', $product, ['from' => $old->value, 'to' => $data['status']]);

        return back()->with('success', "\"{$product->title}\" is now {$data['status']}.");
    }
}
