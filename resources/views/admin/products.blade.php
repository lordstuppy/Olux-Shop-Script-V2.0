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
    <form id="bulk-products" method="post" action="{{ route('admin.bulk.products') }}" class="filters" aria-label="{{ __('Bulk action on products') }}">
        @csrf
        <input type="hidden" name="filter_status" value="{{ $filters['status'] ?? '' }}">
        <x-select name="action" id="bulk-action" :label="__('Bulk action')" :options="['active' => __('Approve (set active)'), 'disabled' => __('Disable'), 'pending_review' => __('Send back to review')]" />
        <x-select name="scope" id="bulk-scope" :label="__('Apply to')" :options="['selected' => __('Ticked products'), 'filtered' => __('All products matching the filter (:count)', ['count' => $products->total()])]" />
        <button type="submit" class="btn-secondary">{{ __('Apply') }}</button>
    </form>
    <div class="table-wrap">
        <table>
            <thead><tr><th scope="col"><span class="visually-hidden">{{ __('Select') }}</span></th><th scope="col">{{ __('Product') }}</th><th scope="col">{{ __('Seller') }}</th><th scope="col" class="num">{{ __('Price') }}</th><th scope="col">{{ __('Delivery') }}</th><th scope="col">{{ __('Files / keys') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col">{{ __('Action') }}</th></tr></thead>
            <tbody>
                @forelse ($products as $product)
                    <tr>
                        <td><input type="checkbox" name="ids[]" value="{{ $product->id }}" form="bulk-products" aria-label="{{ __('Select :title', ['title' => $product->title]) }}"></td>
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
                    <tr><td colspan="8">{{ __('No products.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $products->links() }}
@endsection
