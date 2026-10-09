@extends('layouts.app')

@php $editing = $product->exists; @endphp
@section('title', $editing ? 'Edit '.$product->title : 'New product')
@section('noindex', true)
@section('subnav') @include('partials.seller-nav') @endsection

@section('content')
    <h1>{{ $editing ? 'Edit product' : 'New product' }}</h1>
    @if ($editing)
        <p>Status: <x-status :value="$product->status" />
            @if ($product->status === \App\Enums\ProductStatus::Active)
                &middot; <a href="{{ route('products.show', $product->slug) }}">View in catalog</a>
            @endif
        </p>
    @endif

    <form method="post" action="{{ $editing ? route('seller.products.update', $product->id) : route('seller.products.store') }}" class="stack">
        @csrf
        @if ($editing) @method('PUT') @endif
        <x-field name="title" label="Title" :value="$product->title" maxlength="160" required />
        <x-textarea name="description" label="Description" :value="$product->description" maxlength="20000" hint="Plain text. Describe exactly what the buyer receives." required />
        <x-select name="category_id" label="Category" :options="$categories->pluck('name', 'id')->all()" :value="$product->category_id" placeholder="No category" />
        <x-field name="price" label="Price" :value="$product->price_minor ? \App\Support\Money::toDecimal($product->price_minor, $product->currency) : ''" inputmode="decimal" hint="For example 19.99" required />
        <x-select name="currency" label="Currency" :options="array_combine(\App\Support\Money::supported(), \App\Support\Money::supported())" :value="$product->currency" />
        <x-field name="stock" label="Stock" type="number" :value="$product->stock" min="0" hint="Leave empty for unlimited (for example a downloadable tutorial). Adding licence keys increases stock automatically." />
        <x-field name="access_days" label="Subscription length in days" type="number" :value="$product->access_days" min="1" max="3660" hint="Leave empty for a one-off purchase. For subscriptions, buyers get access for this many days per unit and a reminder before it ends." />
        <x-field name="download_limit" label="Downloads per purchase" type="number" :value="$product->download_limit" min="1" max="1000" hint="Leave empty for the shop default ({{ config('shop.max_downloads_per_item') }})." />
        <x-select name="delivery_type" label="Delivery" :options="['instant' => 'Instant (files or licence keys)', 'manual' => 'Manual (you deliver after payment)']" :value="$product->delivery_type?->value" />
        <button type="submit">{{ $editing ? 'Save changes' : 'Save draft' }}</button>
        @if ($editing && $product->status === \App\Enums\ProductStatus::Active)
            <p class="hint">Changing the title, description, price, currency, category or delivery type sends the product back for review.</p>
        @endif
    </form>

    @if ($editing)
        @if (in_array($product->status, [\App\Enums\ProductStatus::Draft, \App\Enums\ProductStatus::Disabled], true))
            <form method="post" action="{{ route('seller.products.submit', $product->id) }}" class="mt">
                @csrf
                <button type="submit">Submit for review</button>
            </form>
        @endif

        <h2>Files</h2>
        @if ($product->files->isEmpty())
            <p>No files uploaded.</p>
        @else
            <ul>
                @foreach ($product->files as $file)
                    <li>
                        {{ $file->original_name }} ({{ number_format($file->size) }} bytes, SHA-256 <span class="mono">{{ substr($file->checksum, 0, 16) }}...</span>), virus scan: <x-status :value="$file->scan_status" />
                        @if ($file->retired_at)
                            - retired
                        @else
                            <form method="post" action="{{ route('seller.products.files.destroy', [$product->id, $file->id]) }}" class="inline">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn-link">Stop delivering<span class="visually-hidden"> {{ $file->original_name }}</span></button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
        <form method="post" action="{{ route('seller.products.files.store', $product->id) }}" enctype="multipart/form-data" class="stack">
            @csrf
            <x-field name="file" label="Upload a file" type="file" hint="Max {{ (int) (config('shop.max_upload_kb') / 1024) }} MB. Archives, PDF, e-books, media and text files." required />
            <button type="submit" class="btn-secondary">Upload</button>
        </form>

        <h2>Images</h2>
        @if ($product->images->isEmpty())
            <p>No images yet. Products with screenshots sell better.</p>
        @else
            <ul class="image-list">
                @foreach ($product->images as $image)
                    <li>
                        <img src="{{ $image->url('thumb') }}" alt="{{ $image->alt_text ?? 'Product image' }}">
                        <form method="post" action="{{ route('seller.products.images.destroy', [$product->id, $image->id]) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn-link">Remove image<span class="visually-hidden"> {{ $loop->iteration }}</span></button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif
        @if ($product->images->count() < config('shop.max_product_images'))
            <form method="post" action="{{ route('seller.products.images.store', $product->id) }}" enctype="multipart/form-data" class="stack">
                @csrf
                <x-field name="image" label="Add an image" type="file" accept="image/jpeg,image/png,image/webp" hint="JPEG, PNG or WebP, up to {{ (int) (config('shop.max_image_kb') / 1024) }} MB. Images are re-encoded; metadata is removed." required />
                <x-field name="alt_text" label="Image description (for screen readers)" maxlength="160" />
                <button type="submit" class="btn-secondary">Upload image</button>
            </form>
        @endif

        <h2>Licence keys</h2>
        <p>{{ $availableKeys }} available, {{ $assignedKeys }} delivered. Each sold unit receives one key.</p>
        <form method="post" action="{{ route('seller.products.keys.store', $product->id) }}" class="stack">
            @csrf
            <x-textarea name="keys" label="Add keys, one per line" hint="Keys are stored encrypted. Duplicates are skipped." />
            <button type="submit" class="btn-secondary">Add keys</button>
        </form>
    @endif
@endsection
