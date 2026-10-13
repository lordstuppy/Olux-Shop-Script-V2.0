<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductFile;
use App\Services\AuditLogger;
use App\Services\ProductModerationService;
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
        $query = Product::with(['seller', 'category', 'currentFiles:id,product_id', 'images:id,product_id'])->withCount(['files', 'licenseKeys'])->latest('updated_at');
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        return view('admin.products', ['products' => $query->paginate(30)->withQueryString(), 'filters' => $data]);
    }

    /** Full review page: description, images, files with scan status and download links. */
    public function show(Product $product): View
    {
        $product->load(['seller.sellerProfile', 'category', 'images', 'files', 'currentFiles:id,product_id']);

        return view('admin.product-show', [
            'product' => $product,
            'availableKeys' => $product->licenseKeys()->whereNull('order_item_id')->count(),
        ]);
    }

    /** Lets reviewers inspect what a seller uploaded before approving it. */
    public function downloadFile(Product $product, ProductFile $file, AuditLogger $audit): StreamedResponse
    {
        abort_unless($file->product_id === $product->id, 404);
        abort_if($file->scan_status === 'infected', 410, __('This file was removed because it failed the virus scan.'));
        // Not onto a reviewer's computer before the scanner has passed it.
        abort_unless(in_array($file->scan_status, ['clean', 'skipped'], true), 409, __('This file has not passed the virus scan yet. Download it once the scan reports it clean.'));
        $audit->log('product.file_inspected', $product, ['file_id' => $file->id]);

        return Storage::disk('products')->download($file->storage_path, $file->original_name);
    }

    public function status(Request $request, Product $product, ProductModerationService $moderation): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([ProductStatus::Active->value, ProductStatus::Disabled->value, ProductStatus::PendingReview->value])],
            'note' => ['required_if:status,disabled', 'nullable', 'string', 'max:500'],
            'seen_status' => ['nullable', 'string', 'max:32'],
            'version' => ['nullable', 'string', 'max:64'],
        ], ['note.required_if' => __('Tell the seller why the product is not for sale.')]);
        $moderation->setStatus($product, ProductStatus::from($data['status']), $request->user(), $data['note'] ?? null,
            seenStatus: $data['seen_status'] ?? null, seenVersion: $data['version'] ?? null);

        return back()->with('success', __('":title" is now :status.', ['title' => $product->title, 'status' => mb_strtolower($product->status->label())]));
    }
}
