<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ProductStatus;
use App\Exceptions\UserFacingException;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductFile;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    /** Full review page: description, images, files with scan status and download links. */
    public function show(Product $product): View
    {
        $product->load(['seller.sellerProfile', 'category', 'images', 'files']);

        return view('admin.product-show', [
            'product' => $product,
            'availableKeys' => $product->licenseKeys()->whereNull('order_item_id')->count(),
        ]);
    }

    /** Lets reviewers inspect what a seller uploaded before approving it. */
    public function downloadFile(Product $product, ProductFile $file, AuditLogger $audit): StreamedResponse
    {
        abort_unless($file->product_id === $product->id, 404);
        abort_if($file->scan_status === 'infected', 410, 'This file was removed because it failed the virus scan.');
        $audit->log('product.file_inspected', $product, ['file_id' => $file->id]);

        return Storage::disk('products')->download($file->storage_path, $file->original_name);
    }

    public function status(Request $request, Product $product, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in([ProductStatus::Active->value, ProductStatus::Disabled->value])]]);
        $old = $product->status;
        if ($data['status'] === ProductStatus::Active->value) {
            $unscanned = $product->currentFiles()->whereNotIn('scan_status', ['clean', 'skipped'])->count();
            if ($unscanned > 0) {
                throw new UserFacingException("\"{$product->title}\" has {$unscanned} ".($unscanned === 1 ? 'file' : 'files').' without a clean virus scan. Approve it after the scan finishes.');
            }
        }
        $product->status = ProductStatus::from($data['status']);
        $product->save();
        $audit->log('product.status_changed', $product, ['from' => $old->value, 'to' => $data['status']]);

        return back()->with('success', "\"{$product->title}\" is now {$data['status']}.");
    }
}
