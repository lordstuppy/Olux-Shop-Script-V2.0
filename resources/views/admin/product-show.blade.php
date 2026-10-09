@extends('layouts.app')

@section('title', 'Review '.$product->title.' - Admin')
@section('noindex', true)
@section('subnav') @include('partials.admin-nav') @endsection

@section('content')
    <h1>Review: {{ $product->title }}</h1>
    <p>
        Status <x-status :value="$product->status" />
        &middot; Seller <a href="{{ route('admin.users.show', $product->seller) }}">{{ $product->seller->sellerProfile?->display_name ?? $product->seller->email }}</a>
        &middot; {{ money($product->price_minor, $product->currency) }}
        &middot; {{ $product->delivery_type->value }} delivery
        @if ($product->access_days) &middot; {{ $product->access_days }}-day access @endif
        &middot; stock {{ $product->stock ?? 'unlimited' }}
        &middot; {{ $availableKeys }} unused licence keys
    </p>

    <h2>Description</h2>
    <div class="description card">{{ $product->description }}</div>

    <h2>Images</h2>
    @if ($product->images->isEmpty())
        <p>No images.</p>
    @else
        <ul class="image-list">
            @foreach ($product->images as $image)
                <li><a href="{{ $image->url() }}"><img src="{{ $image->url('thumb') }}" alt="{{ $image->alt_text ?? 'Product image' }}"></a></li>
            @endforeach
        </ul>
    @endif

    <h2>Files</h2>
    @if ($product->files->isEmpty())
        <p>No files.</p>
    @else
        <div class="table-wrap">
            <table>
                <thead><tr><th scope="col">File</th><th scope="col" class="num">Size</th><th scope="col">SHA-256</th><th scope="col">Virus scan</th><th scope="col">State</th><th scope="col">Inspect</th></tr></thead>
                <tbody>
                    @foreach ($product->files as $file)
                        <tr>
                            <td>{{ $file->original_name }}</td>
                            <td class="num">{{ number_format($file->size) }}</td>
                            <td class="mono">{{ substr($file->checksum, 0, 16) }}...</td>
                            <td><x-status :value="$file->scan_status" /> {{ $file->scan_detail }}</td>
                            <td>{{ $file->retired_at ? 'Retired' : 'Delivered to new orders' }}</td>
                            <td>
                                @if ($file->scan_status !== 'infected')
                                    <a href="{{ route('admin.products.files.download', [$product->id, $file->id]) }}">Download<span class="visually-hidden"> {{ $file->original_name }}</span></a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <h2>Decision</h2>
    <div class="actions">
        @if ($product->status !== \App\Enums\ProductStatus::Active)
            <form method="post" action="{{ route('admin.products.status', $product->id) }}">
                @csrf
                <input type="hidden" name="status" value="active">
                <button type="submit">Approve and list</button>
            </form>
        @endif
        @if ($product->status !== \App\Enums\ProductStatus::Disabled)
            <form method="post" action="{{ route('admin.products.status', $product->id) }}">
                @csrf
                <input type="hidden" name="status" value="disabled">
                <button type="submit" class="btn-danger">Disable</button>
            </form>
        @endif
    </div>
@endsection
