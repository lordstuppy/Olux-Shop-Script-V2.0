@extends('layouts.app')

@section('title', __('Your products'))
@section('noindex', true)
@section('subnav') @include('partials.seller-nav') @endsection

@section('content')
    <h1>{{ __('Your products') }}</h1>
    <p><a class="btn" href="{{ route('seller.products.create') }}">{{ __('Add a product') }}</a></p>
    @if ($products->isEmpty())
        <p>{{ __('You have not created any products.') }}</p>
    @else
        <div class="table-wrap">
            <table>
                <thead><tr><th scope="col">{{ __('Title') }}</th><th scope="col" class="num">{{ __('Price') }}</th><th scope="col" class="num">{{ __('Stock') }}</th><th scope="col">{{ __('Files / keys') }}</th><th scope="col" class="num">{{ __('Platform commission') }}</th><th scope="col">{{ __('Status') }}</th></tr></thead>
                <tbody>
                    @foreach ($products as $product)
                        <tr>
                            <td><a href="{{ route('seller.products.edit', $product->id) }}">{{ $product->title }}</a></td>
                            <td class="num">{{ money($product->price_minor, $product->currency) }}</td>
                            <td class="num">{{ $product->stock ?? __('Unlimited') }}</td>
                            <td>{{ $product->files_count }} / {{ $product->license_keys_count }}</td>
                            <td class="num">{{ \App\Services\CommissionService::percent(app(\App\Services\CommissionService::class)->resolve($product, $profile ?? null)['bps']) }}</td>
                            <td><x-status :value="$product->status" /></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $products->links() }}
    @endif
@endsection
