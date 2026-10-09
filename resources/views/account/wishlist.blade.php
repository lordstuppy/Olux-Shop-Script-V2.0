@extends('layouts.app')

@section('title', __('Wishlist'))
@section('noindex', true)

@section('content')
    <h1>{{ __('Wishlist') }}</h1>
    @if ($items->isEmpty())
        <p>{{ __('Your wishlist is empty. Use "Save to wishlist" on a product page to keep it for later.') }}</p>
    @else
        <div class="grid">
            @foreach ($items as $item)
                <div>
                    @include('partials.product-card', ['product' => $item->product, 'headingLevel' => 2])
                    <div class="actions mt">
                        @if ($item->product->isPurchasable())
                            <form method="post" action="{{ route('cart.add') }}">
                                @csrf
                                <input type="hidden" name="product_id" value="{{ $item->product->id }}">
                                <button type="submit">{{ __('Add to cart') }}<span class="visually-hidden"> {{ $item->product->title }}</span></button>
                            </form>
                        @endif
                        <form method="post" action="{{ route('wishlist.destroy', $item->product->id) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn-link">{{ __('Remove') }}<span class="visually-hidden"> {{ $item->product->title }}</span></button>
                        </form>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
@endsection
