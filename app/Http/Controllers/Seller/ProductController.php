<?php

namespace App\Http\Controllers\Seller;

use App\Enums\DeliveryType;
use App\Enums\ProductStatus;
use App\Exceptions\UserFacingException;
use App\Http\Controllers\Controller;
use App\Jobs\ScanProductFile;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductFile;
use App\Models\ProductImage;
use App\Models\ProductLicenseKey;
use App\Services\AuditLogger;
use App\Services\ProductImageService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class ProductController extends Controller
{
    /** Uploads with these extensions are accepted; everything is served as an attachment. */
    private const FILE_EXTENSIONS = 'zip,7z,gz,tar,pdf,epub,txt,md,mp3,mp4,png,jpg,jpeg,webp,csv,json';

    /** Changing these on an active product sends it back for review. */
    private const REVIEWED_FIELDS = ['title', 'description', 'price_minor', 'currency', 'delivery_type', 'category_id', 'access_days'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        return view('seller.products.index', [
            'products' => $request->user()->products()->with('category')->withCount(['files', 'licenseKeys'])->latest('id')->paginate(20),
            'profile' => $request->user()->sellerProfile,
        ]);
    }

    public function create(): View
    {
        return view('seller.products.form', ['product' => new Product(['currency' => config('shop.default_currency'), 'delivery_type' => DeliveryType::Instant]), 'categories' => Category::orderBy('name')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $product = new Product($data);
        $product->seller_id = $request->user()->id;
        $product->slug = $this->uniqueSlug($data['title']);
        $product->status = ProductStatus::Draft;
        $product->save();
        $this->audit->log('product.created', $product);

        return redirect()->route('seller.products.edit', $product)->with('success', __('Draft ":title" saved. Add files or licence keys, then submit it for review.', ['title' => $product->title]));
    }

    public function edit(Product $product): View
    {
        Gate::authorize('update', $product);
        $product->load(['files', 'images']);

        return view('seller.products.form', [
            'product' => $product,
            'categories' => Category::orderBy('name')->get(),
            'availableKeys' => $product->licenseKeys()->whereNull('order_item_id')->count(),
            'assignedKeys' => $product->licenseKeys()->whereNotNull('order_item_id')->count(),
        ]);
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        Gate::authorize('update', $product);
        $data = $this->validated($request);
        $product->fill($data);

        $needsReview = in_array($product->status, [ProductStatus::Active, ProductStatus::Paused], true) && $product->isDirty(self::REVIEWED_FIELDS);
        if ($needsReview) {
            $product->status = ProductStatus::PendingReview;
        }
        $product->save();
        $this->audit->log('product.updated', $product, ['needs_review' => $needsReview]);

        $message = $needsReview
            ? __('":title" was updated and is hidden from the catalog until an administrator reviews the changes.', ['title' => $product->title])
            : __('":title" was updated.', ['title' => $product->title]);

        return redirect()->route('seller.products.edit', $product)->with('success', $message);
    }

    public function submit(Product $product): RedirectResponse
    {
        Gate::authorize('update', $product);
        if (! in_array($product->status, [ProductStatus::Draft, ProductStatus::Disabled], true)) {
            throw new UserFacingException(__('":title" is :status and cannot be submitted again.', ['title' => $product->title, 'status' => mb_strtolower($product->status->label())]));
        }
        if (! $product->hasDeliverableContent()) {
            throw new UserFacingException(__('Instant-delivery products need at least one file or licence key before review.'));
        }
        $product->status = ProductStatus::PendingReview;
        $product->save();
        $this->audit->log('product.submitted', $product);

        return back()->with('success', __('":title" was submitted for review.', ['title' => $product->title]));
    }

    /** Takes an approved product off sale; it disappears from the catalog and carts. */
    public function pause(Product $product): RedirectResponse
    {
        Gate::authorize('update', $product);
        if ($product->status !== ProductStatus::Active) {
            throw new UserFacingException(__('Only products on sale can be paused. ":title" is :status.', ['title' => $product->title, 'status' => mb_strtolower($product->status->label())]));
        }
        $product->status = ProductStatus::Paused;
        $product->save();
        $this->audit->log('product.paused', $product);

        return back()->with('success', __('":title" is paused and no longer for sale.', ['title' => $product->title]));
    }

    /** Puts a paused product back on sale without a new review (it was not changed). */
    public function resume(Product $product): RedirectResponse
    {
        Gate::authorize('update', $product);
        if ($product->status !== ProductStatus::Paused) {
            throw new UserFacingException(__('Only paused products can be put back on sale. ":title" is :status.', ['title' => $product->title, 'status' => mb_strtolower($product->status->label())]));
        }
        if (! $product->hasDeliverableContent()) {
            throw new UserFacingException(__('Instant-delivery products need at least one file or licence key before review.'));
        }
        $product->status = ProductStatus::Active;
        $product->save();
        $this->audit->log('product.resumed', $product);

        return back()->with('success', __('":title" is for sale again.', ['title' => $product->title]));
    }

    public function uploadFile(Request $request, Product $product): RedirectResponse
    {
        Gate::authorize('update', $product);
        $maxMb = intdiv((int) config('shop.max_upload_kb'), 1024);
        $request->validate([
            'file' => ['required', 'file', 'max:'.config('shop.max_upload_kb'), 'extensions:'.self::FILE_EXTENSIONS],
        ], [
            'file.uploaded' => __('The file did not arrive: it is larger than :max MB or the upload was interrupted. Try again with a smaller file.', ['max' => $maxMb]),
            'file.max' => __('The file is larger than :max MB.', ['max' => $maxMb]),
        ]);
        $upload = $request->file('file');

        $path = (string) $product->id.'/'.Str::random(40).'.'.strtolower($upload->getClientOriginalExtension());
        $checksum = hash_file('sha256', $upload->getRealPath());
        try {
            Storage::disk('products')->putFileAs((string) $product->id, $upload, basename($path));
            if (hash_file('sha256', Storage::disk('products')->path($path)) !== $checksum) {
                throw new RuntimeException('stored file does not match the upload (short write)');
            }
        } catch (Throwable $e) {
            // Disk full or not writable: no partial file and no database row stay behind.
            rescue(fn () => Storage::disk('products')->delete($path), report: false);
            Log::critical('Could not store an upload for product {product_id}: {reason}', ['product_id' => $product->id, 'reason' => $e->getMessage()]);

            throw new UserFacingException(__('The file could not be saved because of a storage problem on our side. Nothing was stored; please try again later. Our team has been alerted.'));
        }
        $file = $product->files()->create([
            'original_name' => mb_substr(basename($upload->getClientOriginalName()), 0, 255),
            'storage_path' => $path,
            'checksum' => $checksum,
            'size' => $upload->getSize(),
        ]);
        $this->markForReviewIfActive($product);
        $this->audit->log('product.file_added', $product, ['file_id' => $file->id, 'checksum' => $file->checksum]);
        ScanProductFile::dispatch($file->id);

        return back()->with('success', __('Uploaded ":name" (:size bytes). It is delivered to buyers once the virus scan reports it clean.', ['name' => $file->original_name, 'size' => number_format($file->size)]));
    }

    public function uploadImage(Request $request, Product $product, ProductImageService $images): RedirectResponse
    {
        Gate::authorize('update', $product);
        $maxMb = intdiv((int) config('shop.max_image_kb'), 1024);
        $request->validate([
            'image' => ['required', 'file', 'max:'.config('shop.max_image_kb'), 'mimes:jpg,jpeg,png,webp'],
            'alt_text' => ['nullable', 'string', 'max:160'],
        ], [
            'image.uploaded' => __('The image did not arrive: it is larger than :max MB or the upload was interrupted. Try again with a smaller image.', ['max' => $maxMb]),
            'image.max' => __('The image is larger than :max MB.', ['max' => $maxMb]),
        ]);
        $image = $images->store($product, $request->file('image'), $request->input('alt_text'));
        $this->markForReviewIfActive($product);
        $this->audit->log('product.image_added', $product, ['image_id' => $image->id]);

        return back()->with('success', __('Image added (:width x :height).', ['width' => $image->width, 'height' => $image->height]));
    }

    public function deleteImage(Product $product, ProductImage $image, ProductImageService $images): RedirectResponse
    {
        Gate::authorize('update', $product);
        abort_unless($image->product_id === $product->id, 404);
        $images->delete($image);
        $this->audit->log('product.image_removed', $product, ['image_id' => $image->id]);

        return back()->with('success', __('Image removed.'));
    }

    public function deleteFile(Product $product, ProductFile $file): RedirectResponse
    {
        Gate::authorize('update', $product);
        abort_unless($file->product_id === $product->id, 404);
        // Retired files stay downloadable for buyers who already received them
        // but are not delivered to new orders.
        $file->retired_at = now();
        $file->save();
        $this->audit->log('product.file_retired', $product, ['file_id' => $file->id]);

        return back()->with('success', __('":name" will not be delivered to new orders. Existing buyers keep access.', ['name' => $file->original_name]));
    }

    public function addKeys(Request $request, Product $product): RedirectResponse
    {
        Gate::authorize('update', $product);
        $data = $request->validate(['keys' => ['required', 'string', 'max:200000']]);
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $data['keys'])), fn ($line) => $line !== ''));
        $keys = array_values(array_unique($lines));
        if (count($keys) > 1000) {
            throw new UserFacingException(__('Add at most 1000 licence keys at a time.'));
        }

        $added = DB::transaction(function () use ($product, $keys) {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $added = 0;
            foreach ($keys as $key) {
                $fingerprint = hash_hmac('sha256', $key, (string) config('app.key'));
                if (ProductLicenseKey::query()->where('product_id', $locked->id)->where('key_fingerprint', $fingerprint)->exists()) {
                    continue;
                }
                $locked->licenseKeys()->create(['key_encrypted' => mb_substr($key, 0, 500), 'key_fingerprint' => $fingerprint]);
                $added++;
            }
            if ($added > 0) {
                $locked->stock = ($locked->stock ?? 0) + $added;
                $locked->save();
            }

            return $added;
        });
        $this->audit->log('product.keys_added', $product, ['count' => $added]);
        // Duplicates within the submitted list and keys already stored.
        $skipped = count($lines) - $added;

        return back()->with('success', __('Added :added licence keys; stock increased by :added.', ['added' => $added]).($skipped > 0 ? ' '.__('Skipped :skipped duplicates.', ['skipped' => $skipped]) : ''));
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'description' => ['required', 'string', 'max:20000'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'price' => ['required', 'string', 'max:16'],
            'currency' => ['required', Rule::in(Money::supported())],
            'stock' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'delivery_type' => ['required', Rule::enum(DeliveryType::class)],
            'access_days' => ['nullable', 'integer', 'min:1', 'max:3660'],
            'download_limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        try {
            $priceMinor = Money::parseInput($data['price'], $data['currency']);
        } catch (InvalidArgumentException) {
            throw new UserFacingException(__('Enter the price as a number such as 19.99 in :currency.', ['currency' => $data['currency']]));
        }
        if ($priceMinor <= 0 || $priceMinor > 99999999) {
            throw new UserFacingException(__('The price must be between 0.01 and 999999.99.'));
        }

        return [
            'title' => $data['title'],
            'description' => $data['description'],
            'category_id' => $data['category_id'] ?? null,
            'price_minor' => $priceMinor,
            'currency' => $data['currency'],
            'stock' => $data['stock'] ?? null,
            'delivery_type' => DeliveryType::from($data['delivery_type']),
            'access_days' => $data['access_days'] ?? null,
            'download_limit' => $data['download_limit'] ?? null,
        ];
    }

    private function markForReviewIfActive(Product $product): void
    {
        if ($product->status === ProductStatus::Active) {
            $product->status = ProductStatus::PendingReview;
            $product->save();
        }
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::limit(Str::slug($title), 100, '') ?: 'product';
        $slug = $base;
        while (Product::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(5));
        }

        return $slug;
    }
}
