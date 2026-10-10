@extends('layouts.app')

@section('title', __('Review :title - Admin', ['title' => $product->title]))
@section('noindex', true)
@section('subnav') @include('partials.admin-nav') @endsection

@section('content')
    <h1>{{ __('Review: :title', ['title' => $product->title]) }}</h1>
    <p>
        {{ __('Status') }} <x-status :value="$product->status" />
        &middot; {{ __('Seller') }} <a href="{{ route('admin.users.show', $product->seller) }}">{{ $product->seller->sellerProfile?->display_name ?? $product->seller->email }}</a>
        &middot; {{ money($product->price_minor, $product->currency) }}
        &middot; {{ __(':type delivery', ['type' => mb_strtolower($product->delivery_type->label())]) }}
        @if ($product->access_days) &middot; {{ __(':days-day access', ['days' => $product->access_days]) }} @endif
        &middot; {{ __('stock :stock', ['stock' => $product->stock ?? __('unlimited')]) }}
        &middot; {{ __(':count unused licence keys', ['count' => $availableKeys]) }}
        @php $commission = app(\App\Services\CommissionService::class)->resolve($product); @endphp
        &middot; {{ __('commission :percent (:source)', ['percent' => \App\Services\CommissionService::percent($commission['bps']), 'source' => \App\Services\CommissionService::sourceLabel($commission['source'])]) }}
        <span class="muted">#{{ $product->id }}</span>
    </p>

    <h2>{{ __('Description') }}</h2>
    <div class="description card">{{ $product->description }}</div>

    <h2>{{ __('Images') }}</h2>
    @if ($product->images->isEmpty())
        <p>{{ __('No images.') }}</p>
    @else
        <ul class="image-list">
            @foreach ($product->images as $image)
                <li><a href="{{ $image->url() }}"><img src="{{ $image->url('thumb') }}" alt="{{ $image->alt_text ?? __('Product image') }}"></a></li>
            @endforeach
        </ul>
    @endif

    <h2>{{ __('Files') }}</h2>
    @if ($product->files->isEmpty())
        <p>{{ __('No files.') }}</p>
    @else
        <div class="table-wrap">
            <table>
                <thead><tr><th scope="col">{{ __('File') }}</th><th scope="col" class="num">{{ __('Size') }}</th><th scope="col">{{ __('SHA-256') }}</th><th scope="col">{{ __('Virus scan') }}</th><th scope="col">{{ __('State') }}</th><th scope="col">{{ __('Inspect') }}</th></tr></thead>
                <tbody>
                    @foreach ($product->files as $file)
                        <tr>
                            <td>{{ $file->original_name }}</td>
                            <td class="num">{{ number_format($file->size) }}</td>
                            <td class="mono">{{ substr($file->checksum, 0, 16) }}...</td>
                            <td><x-status :value="$file->scan_status" :label="\App\Support\Labels::scanStatus($file->scan_status)" /> {{ $file->scan_detail }}</td>
                            <td>{{ $file->retired_at ? __('Retired') : __('Delivered to new orders') }}</td>
                            <td>
                                @if ($file->scan_status !== 'infected')
                                    <a href="{{ route('admin.products.files.download', [$product->id, $file->id]) }}">{{ __('Download') }}<span class="visually-hidden"> {{ $file->original_name }}</span></a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <h2>{{ __('Decision') }}</h2>
    <div class="actions">
        @if (! in_array($product->status, [\App\Enums\ProductStatus::Active, \App\Enums\ProductStatus::Paused], true))
            <form method="post" action="{{ route('admin.products.status', $product->id) }}">
                @csrf
                <input type="hidden" name="status" value="active">
                <button type="submit">{{ __('Approve and list') }}</button>
            </form>
        @endif
        @if ($product->status !== \App\Enums\ProductStatus::Disabled)
            <form method="post" action="{{ route('admin.products.status', $product->id) }}" class="stack">
                @csrf
                <input type="hidden" name="status" value="disabled">
                <x-textarea name="note" :label="__('Reason for the seller')" maxlength="500" rows="3" :hint="__('Shown to the seller on the product page and sent by email.')" required />
                <button type="submit" class="btn-danger">{{ $product->status === \App\Enums\ProductStatus::PendingReview ? __('Reject') : __('Disable') }}</button>
            </form>
        @endif
    </div>
@endsection
