<?php

namespace App\Http\Controllers\Seller;

use App\Enums\DeliveryType;
use App\Enums\ProductStatus;
use App\Exceptions\UserFacingException;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductFile;
use App\Models\ProductLicenseKey;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

class ProductController extends Controller
{
    /** Uploads with these extensions are accepted; everything is served as an attachment. */
    private const FILE_EXTENSIONS = 'zip,7z,gz,tar,pdf,epub,txt,md,mp3,mp4,png,jpg,jpeg,webp,csv,json';

    /** Changing these on an active product sends it back for review. */
    private const REVIEWED_FIELDS = ['title', 'description', 'price_minor', 'currency', 'delivery_type', 'category_id'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        return view('seller.products.index', [
            'products' => $request->user()->products()->withCount(['files', 'licenseKeys'])->latest('id')->paginate(20),
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

        return redirect()->route('seller.products.edit', $product)->with('success', "Draft \"{$product->title}\" saved. Add files or licence keys, then submit it for review.");
    }

    public function edit(Product $product): View
    {
        Gate::authorize('update', $product);
        $product->load(['files']);

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

        $needsReview = $product->status === ProductStatus::Active && $product->isDirty(self::REVIEWED_FIELDS);
        if ($needsReview) {
            $product->status = ProductStatus::PendingReview;
        }
        $product->save();
        $this->audit->log('product.updated', $product, ['needs_review' => $needsReview]);

        $message = $needsReview
            ? "\"{$product->title}\" was updated and is hidden from the catalog until an administrator reviews the changes."
            : "\"{$product->title}\" was updated.";

        return redirect()->route('seller.products.edit', $product)->with('success', $message);
    }

    public function submit(Product $product): RedirectResponse
    {
        Gate::authorize('update', $product);
        if (! in_array($product->status, [ProductStatus::Draft, ProductStatus::Disabled], true)) {
            throw new UserFacingException("\"{$product->title}\" is {$product->status->value} and cannot be submitted again.");
        }
        if ($product->delivery_type === DeliveryType::Instant && ! $product->activeFiles()->exists() && ! $product->licenseKeys()->exists()) {
            throw new UserFacingException('Instant-delivery products need at least one file or licence key before review.');
        }
        $product->status = ProductStatus::PendingReview;
        $product->save();
        $this->audit->log('product.submitted', $product);

        return back()->with('success', "\"{$product->title}\" was submitted for review.");
    }

    public function uploadFile(Request $request, Product $product): RedirectResponse
    {
        Gate::authorize('update', $product);
        $request->validate([
            'file' => ['required', 'file', 'max:'.config('shop.max_upload_kb'), 'extensions:'.self::FILE_EXTENSIONS],
        ]);
        $upload = $request->file('file');

        $path = $upload->storeAs((string) $product->id, Str::random(40).'.'.strtolower($upload->getClientOriginalExtension()), 'products');
        $file = $product->files()->create([
            'original_name' => mb_substr(basename($upload->getClientOriginalName()), 0, 255),
            'storage_path' => $path,
            'checksum' => hash_file('sha256', Storage::disk('products')->path($path)),
            'size' => $upload->getSize(),
        ]);
        $this->markForReviewIfActive($product);
        $this->audit->log('product.file_added', $product, ['file_id' => $file->id, 'checksum' => $file->checksum]);

        return back()->with('success', "Uploaded \"{$file->original_name}\" (".number_format($file->size).' bytes).');
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

        return back()->with('success', "\"{$file->original_name}\" will not be delivered to new orders. Existing buyers keep access.");
    }

    public function addKeys(Request $request, Product $product): RedirectResponse
    {
        Gate::authorize('update', $product);
        $data = $request->validate(['keys' => ['required', 'string', 'max:200000']]);
        $keys = array_values(array_unique(array_filter(array_map('trim', preg_split('/\R/', $data['keys'])))));
        if (count($keys) > 1000) {
            throw new UserFacingException('Add at most 1000 licence keys at a time.');
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
        $skipped = count($keys) - $added;

        return back()->with('success', "Added {$added} licence keys; stock increased by {$added}.".($skipped > 0 ? " Skipped {$skipped} duplicates." : ''));
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
        ]);

        try {
            $priceMinor = Money::parseInput($data['price'], $data['currency']);
        } catch (InvalidArgumentException) {
            throw new UserFacingException("Enter the price as a number such as 19.99 in {$data['currency']}.");
        }
        if ($priceMinor <= 0 || $priceMinor > 99999999) {
            throw new UserFacingException('The price must be between 0.01 and 999999.99.');
        }

        return [
            'title' => $data['title'],
            'description' => $data['description'],
            'category_id' => $data['category_id'] ?? null,
            'price_minor' => $priceMinor,
            'currency' => $data['currency'],
            'stock' => $data['stock'] ?? null,
            'delivery_type' => DeliveryType::from($data['delivery_type']),
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
