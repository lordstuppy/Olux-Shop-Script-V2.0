@extends('layouts.app')

@section('title', 'Your products')
@section('noindex', true)
@section('subnav') @include('partials.seller-nav') @endsection

@section('content')
    <h1>Your products</h1>
    <p><a class="btn" href="{{ route('seller.products.create') }}">Add a product</a></p>
    @if ($products->isEmpty())
        <p>You have not created any products.</p>
    @else
        <div class="table-wrap">
            <table>
                <thead><tr><th scope="col">Title</th><th scope="col" class="num">Price</th><th scope="col" class="num">Stock</th><th scope="col">Files / keys</th><th scope="col">Status</th></tr></thead>
                <tbody>
                    @foreach ($products as $product)
                        <tr>
                            <td><a href="{{ route('seller.products.edit', $product->id) }}">{{ $product->title }}</a></td>
                            <td class="num">{{ money($product->price_minor, $product->currency) }}</td>
                            <td class="num">{{ $product->stock ?? 'Unlimited' }}</td>
                            <td>{{ $product->files_count }} / {{ $product->license_keys_count }}</td>
                            <td><x-status :value="$product->status" /></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $products->links() }}
    @endif
@endsection
