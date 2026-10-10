@extends('layouts.app')

@php $editing = $product->exists; @endphp
@section('title', $editing ? __('Edit :title', ['title' => $product->title]) : __('New product'))
@section('noindex', true)
@section('subnav') @include('partials.seller-nav') @endsection

@section('content')
    <h1>{{ $editing ? __('Edit product') : __('New product') }}</h1>
    @if ($editing)
        <p>{{ __('Status:') }} <x-status :value="$product->status" />
            @if ($product->status === \App\Enums\ProductStatus::Active)
                &middot; <a href="{{ route('products.show', $product->slug) }}">{{ __('View in catalog') }}</a>
            @endif
        </p>
    @endif

    <form method="post" action="{{ $editing ? route('seller.products.update', $product->id) : route('seller.products.store') }}" class="stack">
        @csrf
        @if ($editing) @method('PUT') @endif
        <x-field name="title" :label="__('Title')" :value="$product->title" maxlength="160" required />
        <x-textarea name="description" :label="__('Description')" :value="$product->description" maxlength="20000" :hint="__('Plain text. Describe exactly what the buyer receives.')" required />
        <x-select name="category_id" :label="__('Category')" :options="$categories->pluck('name', 'id')->all()" :value="$product->category_id" :placeholder="__('No category')" />
        <x-field name="price" :label="__('Price')" :value="$product->price_minor ? \App\Support\Money::toDecimal($product->price_minor, $product->currency) : ''" inputmode="decimal" :hint="__('For example 19.99')" required />
        <x-select name="currency" :label="__('Currency')" :options="array_combine(\App\Support\Money::supported(), \App\Support\Money::supported())" :value="$product->currency" />
        <x-field name="stock" :label="__('Stock')" type="number" :value="$product->stock" min="0" :hint="__('Leave empty for unlimited (for example a downloadable tutorial). Adding licence keys increases stock automatically.')" />
        <x-field name="access_days" :label="__('Subscription length in days')" type="number" :value="$product->access_days" min="1" max="3660" :hint="__('Leave empty for a one-off purchase. For subscriptions, buyers get access for this many days per unit and a reminder before it ends.')" />
        <x-field name="download_limit" :label="__('Downloads per purchase')" type="number" :value="$product->download_limit" min="1" max="1000" :hint="__('Leave empty for the shop default (:limit).', ['limit' => config('shop.max_downloads_per_item')])" />
        <x-select name="delivery_type" :label="__('Delivery')" :options="['instant' => __('Instant (files or licence keys)'), 'manual' => __('Manual (you deliver after payment)')]" :value="$product->delivery_type?->value" />
        <button type="submit">{{ $editing ? __('Save changes') : __('Save draft') }}</button>
        @if ($editing && in_array($product->status, [\App\Enums\ProductStatus::Active, \App\Enums\ProductStatus::Paused], true))
            <p class="hint">{{ __('Changing the title, description, price, currency, category or delivery type sends the product back for review.') }}</p>
        @endif
    </form>

    @if ($editing)
        @if (in_array($product->status, [\App\Enums\ProductStatus::Draft, \App\Enums\ProductStatus::Disabled], true))
            <form method="post" action="{{ route('seller.products.submit', $product->id) }}" class="mt">
                @csrf
                <button type="submit">{{ __('Submit for review') }}</button>
            </form>
        @endif
        @if ($product->status === \App\Enums\ProductStatus::Active)
            <form method="post" action="{{ route('seller.products.pause', $product->id) }}" class="mt">
                @csrf
                <button type="submit" class="btn-secondary">{{ __('Pause sales') }}</button>
                <span class="hint">{{ __('Hides the product from the catalog until you resume it. Buyers keep what they bought.') }}</span>
            </form>
        @elseif ($product->status === \App\Enums\ProductStatus::Paused)
            <form method="post" action="{{ route('seller.products.resume', $product->id) }}" class="mt">
                @csrf
                <button type="submit">{{ __('Resume sales') }}</button>
            </form>
        @endif

        <h2>{{ __('Files') }}</h2>
        @if ($product->files->isEmpty())
            <p>{{ __('No files uploaded.') }}</p>
        @else
            <ul>
                @foreach ($product->files as $file)
                    <li>
                        {{ $file->original_name }} ({{ __(':size bytes', ['size' => number_format($file->size)]) }}, SHA-256 <span class="mono">{{ substr($file->checksum, 0, 16) }}...</span>), {{ __('virus scan:') }} <x-status :value="$file->scan_status" :label="\App\Support\Labels::scanStatus($file->scan_status)" />
                        @if ($file->retired_at)
                            - {{ __('retired') }}
                        @else
                            <form method="post" action="{{ route('seller.products.files.destroy', [$product->id, $file->id]) }}" class="inline">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn-link">{{ __('Stop delivering') }}<span class="visually-hidden"> {{ $file->original_name }}</span></button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
        <form method="post" action="{{ route('seller.products.files.store', $product->id) }}" enctype="multipart/form-data" class="stack">
            @csrf
            <x-field name="file" :label="__('Upload a file')" type="file" :hint="__('Max :size MB. Archives, PDF, e-books, media and text files.', ['size' => (int) (config('shop.max_upload_kb') / 1024)])" required />
            <button type="submit" class="btn-secondary">{{ __('Upload') }}</button>
        </form>

        <h2>{{ __('Images') }}</h2>
        @if ($product->images->isEmpty())
            <p>{{ __('No images yet. Products with screenshots sell better.') }}</p>
        @else
            <ul class="image-list">
                @foreach ($product->images as $image)
                    <li>
                        <img src="{{ $image->url('thumb') }}" alt="{{ $image->alt_text ?? __('Product image') }}">
                        <form method="post" action="{{ route('seller.products.images.destroy', [$product->id, $image->id]) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn-link">{{ __('Remove image') }}<span class="visually-hidden"> {{ $loop->iteration }}</span></button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif
        @if ($product->images->count() < config('shop.max_product_images'))
            <form method="post" action="{{ route('seller.products.images.store', $product->id) }}" enctype="multipart/form-data" class="stack">
                @csrf
                <x-field name="image" :label="__('Add an image')" type="file" accept="image/jpeg,image/png,image/webp" :hint="__('JPEG, PNG or WebP, up to :size MB. Images are re-encoded; metadata is removed.', ['size' => (int) (config('shop.max_image_kb') / 1024)])" required />
                <x-field name="alt_text" :label="__('Image description (for screen readers)')" maxlength="160" />
                <button type="submit" class="btn-secondary">{{ __('Upload image') }}</button>
            </form>
        @endif

        <h2>{{ __('Licence keys') }}</h2>
        <p>{{ __(':available available, :assigned delivered. Each sold unit receives one key.', ['available' => $availableKeys, 'assigned' => $assignedKeys]) }}</p>
        <form method="post" action="{{ route('seller.products.keys.store', $product->id) }}" class="stack">
            @csrf
            <x-textarea name="keys" :label="__('Add keys, one per line')" :hint="__('Keys are stored encrypted. Duplicates are skipped.')" />
            <button type="submit" class="btn-secondary">{{ __('Add keys') }}</button>
        </form>
    @endif
@endsection
