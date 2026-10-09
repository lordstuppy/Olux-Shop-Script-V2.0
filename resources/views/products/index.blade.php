@extends('layouts.app')

@section('title', 'Products')
@section('canonical', route('products.index', array_filter(['category' => $filters['category'] ?? null, 'page' => request('page') > 1 ? (int) request('page') : null])))

@section('content')
    <h1>Products</h1>

    <form class="filters" method="get" action="{{ route('products.index') }}" aria-label="Filter products">
        <x-field name="q" label="Search" type="search" :value="$filters['q'] ?? ''" maxlength="100" />
        <x-select name="category" label="Category" :options="$categories->pluck('name', 'slug')->all()" :value="$filters['category'] ?? ''" placeholder="All categories" />
        <x-select name="sort" label="Sort by" :options="['newest' => 'Newest', 'price_asc' => 'Price: low to high', 'price_desc' => 'Price: high to low', 'title' => 'Title']" :value="$filters['sort'] ?? 'newest'" />
        <x-select name="currency" label="Listed in" :options="array_combine(\App\Support\Money::supported(), \App\Support\Money::supported())" :value="$filters['currency'] ?? ''" placeholder="Any currency" />
        <button type="submit">Apply filters</button>
    </form>

    <p class="muted" role="status">{{ $products->total() }} {{ $products->total() === 1 ? 'product' : 'products' }} found.</p>

    @if ($products->isEmpty())
        <p>No products match these filters. <a href="{{ route('products.index') }}">Clear filters</a>.</p>
    @else
        <div class="grid">
            @foreach ($products as $product)
                @include('partials.product-card', ['product' => $product])
            @endforeach
        </div>
        {{ $products->links() }}
    @endif
@endsection
