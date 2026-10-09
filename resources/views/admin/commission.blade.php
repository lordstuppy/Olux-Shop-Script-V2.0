@extends('layouts.app')

@section('title', __('Commission - Admin'))
@section('noindex', true)
@section('subnav') @include('partials.admin-nav') @endsection

@section('content')
    <h1>{{ __('Commission') }}</h1>
    <p>{{ __('The platform keeps a commission on every sale. The most specific rate applies: product override, then seller rate, then category rate, then the global default. The rate is frozen on each order line when the order is placed; changes apply to new orders only.') }}</p>
    <p>{{ __('Global default:') }} <strong>{{ \App\Services\CommissionService::percent($default) }}</strong>
        @can('settings.manage') (<a href="{{ route('admin.settings.index') }}">{{ __('change in Settings') }}</a>) @endcan</p>
    <p class="hint">{{ __('Enter a percentage such as 12.5. Leave a field empty to remove that level so the next one applies.') }}</p>

    <h2>{{ __('Categories') }}</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th scope="col">{{ __('Category') }}</th><th scope="col">{{ __('Rate') }}</th></tr></thead>
            <tbody>
                @forelse ($categories as $category)
                    <tr>
                        <td>{{ $category->name }}</td>
                        <td>
                            <form method="post" action="{{ route('admin.commission.category', $category->id) }}" class="actions">
                                @csrf
                                @method('PUT')
                                <x-field name="commission_percent" :id="'cat-'.$category->id" :label="__('Commission % for :name', ['name' => $category->name])" :value="$category->commission_bps !== null ? number_format($category->commission_bps / 100, 2, '.', '') : ''" inputmode="decimal" maxlength="6" />
                                <button type="submit" class="btn-secondary">{{ __('Save') }}</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="2">{{ __('No categories yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <h2>{{ __('Sellers') }}</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th scope="col">{{ __('Seller') }}</th><th scope="col">{{ __('Rate') }}</th></tr></thead>
            <tbody>
                @forelse ($sellers as $profile)
                    <tr>
                        <td>{{ $profile->display_name }}<br><span class="muted">{{ $profile->user->email }}</span></td>
                        <td>
                            <form method="post" action="{{ route('admin.commission.seller', $profile) }}" class="actions">
                                @csrf
                                @method('PUT')
                                <x-field name="commission_percent" :id="'seller-'.$profile->id" :label="__('Commission % for :name', ['name' => $profile->display_name])" :value="$profile->commission_bps !== null ? number_format($profile->commission_bps / 100, 2, '.', '') : ''" inputmode="decimal" maxlength="6" />
                                <button type="submit" class="btn-secondary">{{ __('Save') }}</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="2">{{ __('No approved sellers yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <h2>{{ __('Product overrides') }}</h2>
    @if ($products->isEmpty())
        <p>{{ __('No product has its own rate.') }}</p>
    @else
        <div class="table-wrap">
            <table>
                <thead><tr><th scope="col">{{ __('Product') }}</th><th scope="col" class="num">{{ __('Rate') }}</th></tr></thead>
                <tbody>
                    @foreach ($products as $product)
                        <tr><td><a href="{{ route('admin.products.show', $product->id) }}">{{ $product->title }}</a> <span class="muted">#{{ $product->id }}</span></td><td class="num">{{ \App\Services\CommissionService::percent($product->commission_bps) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
    <form method="post" action="{{ route('admin.commission.product') }}" class="stack mt">
        @csrf
        @method('PUT')
        <x-field name="product_id" :label="__('Product number')" type="number" min="1" required :hint="__('Shown as #number on the product pages in the admin area.')" />
        <x-field name="commission_percent" id="product-commission" :label="__('Commission %')" inputmode="decimal" maxlength="6" :hint="__('Leave empty to remove the product override.')" />
        <button type="submit">{{ __('Set product rate') }}</button>
    </form>
@endsection
