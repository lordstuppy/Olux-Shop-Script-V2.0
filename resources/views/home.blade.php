@extends('layouts.app')

@section('content')
    <h1>{{ __('Digital tools, tutorials and licences') }}</h1>
    <p>{{ __('Buy software licences, tutorials, templates and subscriptions from independent sellers. Pay with crypto through our self-hosted payment server or with your shop balance. Everything works without JavaScript.') }}</p>
    <p><a class="btn" href="{{ route('products.index') }}">{{ __('Browse all products') }}</a></p>

    @if ($categories->isNotEmpty())
        <h2>{{ __('Categories') }}</h2>
        <ul class="nav">
            @foreach ($categories as $category)
                <li><a href="{{ route('products.index', ['category' => $category->slug]) }}">{{ $category->name }}</a></li>
            @endforeach
        </ul>
    @endif

    <h2>{{ __('New arrivals') }}</h2>
    @if ($latest->isEmpty())
        <p>{{ __('No products are listed yet.') }}</p>
    @else
        <div class="grid">
            @foreach ($latest as $product)
                @include('partials.product-card', ['product' => $product])
            @endforeach
        </div>
    @endif
@endsection
