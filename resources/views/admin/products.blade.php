@extends('layouts.app')

@section('title', __('Products - Admin'))
@section('noindex', true)
@section('subnav') @include('partials.admin-nav') @endsection

@section('content')
    <h1>{{ __('Products') }}</h1>
    <form class="filters" method="get">
        <x-select name="status" :label="__('Status')" :options="collect(\App\Enums\ProductStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all()" :value="$filters['status'] ?? ''" :placeholder="__('Any status')" />
        <button type="submit">{{ __('Filter') }}</button>
    </form>
    <div class="table-wrap">
        <table>
            <thead><tr><th scope="col">{{ __('Product') }}</th><th scope="col">{{ __('Seller') }}</th><th scope="col" class="num">{{ __('Price') }}</th><th scope="col">{{ __('Delivery') }}</th><th scope="col">{{ __('Files / keys') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col">{{ __('Action') }}</th></tr></thead>
            <tbody>
                @forelse ($products as $product)
                    <tr>
                        <td><a href="{{ route('admin.products.show', $product->id) }}">{{ $product->title }}</a><br><span class="muted">{{ \Illuminate\Support\Str::limit($product->description, 160) }}</span></td>
                        <td><a href="{{ route('admin.users.show', $product->seller) }}">{{ $product->seller->email }}</a></td>
                        <td class="num">{{ money($product->price_minor, $product->currency) }}</td>
                        <td>{{ $product->delivery_type->label() }}</td>
                        <td>{{ $product->files_count }} / {{ $product->license_keys_count }}</td>
                        <td><x-status :value="$product->status" /></td>
                        <td>
                            @if ($product->status !== \App\Enums\ProductStatus::Active)
                                <form method="post" action="{{ route('admin.products.status', $product->id) }}" class="inline">
                                    @csrf
                                    <input type="hidden" name="status" value="active">
                                    <button type="submit">{{ __('Approve') }}<span class="visually-hidden"> {{ $product->title }}</span></button>
                                </form>
                            @endif
                            @if ($product->status !== \App\Enums\ProductStatus::Disabled)
                                <form method="post" action="{{ route('admin.products.status', $product->id) }}" class="inline">
                                    @csrf
                                    <input type="hidden" name="status" value="disabled">
                                    <button type="submit" class="btn-danger">{{ __('Disable') }}<span class="visually-hidden"> {{ $product->title }}</span></button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7">{{ __('No products.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $products->links() }}
@endsection
