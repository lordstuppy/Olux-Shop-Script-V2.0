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
        $needsReview = DB::transaction(function () use ($request, $product, $data) {
            // Sales, key uploads and staff decisions change the row while the form is
            // open; work on the current row, not on what the form was rendered from.
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            if ($request->has('stock_seen') && (string) ($data['stock'] ?? '') === (string) $request->input('stock_seen')) {
                // Stock field left as shown: keep what sales and key uploads made of it since.
                unset($data['stock']);
            }
            $locked->fill($data);
            if ($locked->isDirty('stock') && $locked->licenseKeys()->exists()) {
                $unused = $locked->licenseKeys()->whereNull('order_item_id')->count();
                if ($locked->stock === null || $locked->stock > $unused) {
                    throw new UserFacingException(trans_choice('{1} ":title" sells one licence key per unit and has :count unused key, so its stock can be at most :count.|[0,*] ":title" sells one licence key per unit and has :count unused keys, so its stock can be at most :count.', $unused, ['title' => $locked->title]));
                }
            }

            $needsReview = in_array($locked->status, [ProductStatus::Active, ProductStatus::Paused], true) && $locked->isDirty(self::REVIEWED_FIELDS);
            if ($needsReview) {
                $locked->status = ProductStatus::PendingReview;
            }
            $locked->save();
            $product->setRawAttributes($locked->getAttributes(), true);

            return $needsReview;
        });
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
        // Conditional update: a staff decision made a moment ago must not be overwritten.
        if (Product::query()->whereKey($product->id)->where('status', ProductStatus::Active->value)->update(['status' => ProductStatus::Paused->value]) !== 1) {
            throw new UserFacingException(__('":title" changed status a moment ago. Reload the page and try again.', ['title' => $product->title]));
        }
        $this->audit->log('product.paused', $product);

        return back()->with('success', __('":title" is paused and no longer for sale.', ['title' => $product->title]));
    }

    /**
     * Puts a paused product back on sale without a new review. Any change to
     * reviewed content while paused already moved it to review (see
     * markForReviewIfActive and update()), so a paused product is unchanged.
     */
    public function resume(Product $product): RedirectResponse
    {
        Gate::authorize('update', $product);
        if ($product->status !== ProductStatus::Paused) {
            throw new UserFacingException(__('Only paused products can be put back on sale. ":title" is :status.', ['title' => $product->title, 'status' => mb_strtolower($product->status->label())]));
        }
        if (! $product->hasDeliverableContent()) {
            throw new UserFacingException(__('Instant-delivery products need at least one file or licence key before review.'));
        }
        if ($product->currentFiles()->whereNotIn('scan_status', ['clean', 'skipped'])->exists()) {
            throw new UserFacingException(__('":title" has a file that has not been scanned clean yet. Try again when the scan has finished.', ['title' => $product->title]));
        }
        if (Product::query()->whereKey($product->id)->where('status', ProductStatus::Paused->value)->update(['status' => ProductStatus::Active->value]) !== 1) {
            throw new UserFacingException(__('":title" changed status a moment ago. Reload the page and try again.', ['title' => $product->title]));
        }
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
        $this->assertStorageRoom($product, (int) $upload->getSize());

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
            $byFingerprint = [];
            foreach ($keys as $key) {
                $byFingerprint[hash_hmac('sha256', $key, (string) config('app.key'))] = $key;
            }
            // One lookup and batched inserts instead of two queries per key (up to
            // 2,000 statements while the product row is locked).
            $existing = ProductLicenseKey::query()->where('product_id', $locked->id)
                ->whereIn('key_fingerprint', array_keys($byFingerprint))->pluck('key_fingerprint')->flip();
            $now = now();
            $rows = [];
            foreach ($byFingerprint as $fingerprint => $key) {
                if (! isset($existing[$fingerprint])) {
                    // Through the model so key_encrypted is encrypted by its cast.
                    $rows[] = (new ProductLicenseKey)->forceFill(['product_id' => $locked->id, 'key_encrypted' => mb_substr($key, 0, 500),
                        'key_fingerprint' => $fingerprint, 'created_at' => $now, 'updated_at' => $now])->getAttributes();
                }
            }
            foreach (array_chunk($rows, 200) as $chunk) {
                ProductLicenseKey::query()->insert($chunk);
            }
            $added = count($rows);
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
            'title' => ['required', 'string', 'max:160', 'not_regex:/[\r\n]/'],
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

    /** New files, images or keys on a product that is (or can return to being) on sale need a new review. */
    /** Per-product file count and per-seller storage quota, checked before anything is written. */
    private function assertStorageRoom(Product $product, int $bytes): void
    {
        $max = (int) config('shop.max_product_files');
        if ($product->files()->whereNull('retired_at')->count() >= $max) {
            throw new UserFacingException(__('A product can deliver at most :max files. Remove one first.', ['max' => $max]));
        }
        $quotaMb = (int) config('shop.seller_storage_mb');
        $used = (int) ProductFile::query()->whereIn('product_id', Product::query()->select('id')->where('seller_id', $product->seller_id))->sum('size');
        if ($used + $bytes > $quotaMb * 1024 * 1024) {
            throw new UserFacingException(__('Your files would use more than your :max MB of storage. Remove files you no longer sell, or contact support for more space.', ['max' => number_format($quotaMb)]));
        }
    }

    private function markForReviewIfActive(Product $product): void
    {
        // Conditional: a staff decision made a moment ago (for example a disable) is kept.
        $changed = Product::query()->whereKey($product->id)->whereIn('status', [ProductStatus::Active->value, ProductStatus::Paused->value])
            ->update(['status' => ProductStatus::PendingReview->value, 'updated_at' => now()]);
        if ($changed === 1) {
            $product->status = ProductStatus::PendingReview;
            $product->syncOriginalAttribute('status');
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
