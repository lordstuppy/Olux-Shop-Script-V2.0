@extends('layouts.app')

@section('title', 'Products - Admin')
@section('noindex', true)
@section('subnav') @include('partials.admin-nav') @endsection

@section('content')
    <h1>Products</h1>
    <form class="filters" method="get">
        <x-select name="status" label="Status" :options="collect(\App\Enums\ProductStatus::cases())->mapWithKeys(fn ($s) => [$s->value => str_replace('_', ' ', ucfirst($s->value))])->all()" :value="$filters['status'] ?? ''" placeholder="Any status" />
        <button type="submit">Filter</button>
    </form>
    <div class="table-wrap">
        <table>
            <thead><tr><th scope="col">Product</th><th scope="col">Seller</th><th scope="col" class="num">Price</th><th scope="col">Delivery</th><th scope="col">Files / keys</th><th scope="col">Status</th><th scope="col">Action</th></tr></thead>
            <tbody>
                @forelse ($products as $product)
                    <tr>
                        <td>{{ $product->title }}<br><span class="muted">{{ \Illuminate\Support\Str::limit($product->description, 160) }}</span></td>
                        <td><a href="{{ route('admin.users.show', $product->seller) }}">{{ $product->seller->email }}</a></td>
                        <td class="num">{{ money($product->price_minor, $product->currency) }}</td>
                        <td>{{ $product->delivery_type->value }}</td>
                        <td>{{ $product->files_count }} / {{ $product->license_keys_count }}</td>
                        <td><x-status :value="$product->status" /></td>
                        <td>
                            @if ($product->status !== \App\Enums\ProductStatus::Active)
                                <form method="post" action="{{ route('admin.products.status', $product->id) }}" class="inline">
                                    @csrf
                                    <input type="hidden" name="status" value="active">
                                    <button type="submit">Approve<span class="visually-hidden"> {{ $product->title }}</span></button>
                                </form>
                            @endif
                            @if ($product->status !== \App\Enums\ProductStatus::Disabled)
                                <form method="post" action="{{ route('admin.products.status', $product->id) }}" class="inline">
                                    @csrf
                                    <input type="hidden" name="status" value="disabled">
                                    <button type="submit" class="btn-danger">Disable<span class="visually-hidden"> {{ $product->title }}</span></button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7">No products.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $products->links() }}
@endsection
