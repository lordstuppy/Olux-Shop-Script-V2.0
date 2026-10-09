@extends('layouts.app')

@section('title', __('Orders - Admin'))
@section('noindex', true)
@section('subnav') @include('partials.admin-nav') @endsection

@section('content')
    <h1>{{ __('Orders') }}</h1>
    <form class="filters" method="get">
        <x-field name="q" :label="__('Order id starts with')" :value="$filters['q'] ?? ''" maxlength="64" />
        <x-select name="status" :label="__('Status')" :options="collect(\App\Enums\OrderStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all()" :value="$filters['status'] ?? ''" :placeholder="__('Any status')" />
        <x-field name="buyer" :label="__('Buyer email contains')" :value="$filters['buyer'] ?? ''" maxlength="255" />
        <x-select name="seller" :label="__('Seller')" :options="$sellers->all()" :value="$filters['seller'] ?? ''" :placeholder="__('Any seller')" />
        <x-field name="from" :label="__('Placed from')" type="date" :value="$filters['from'] ?? ''" />
        <x-field name="to" :label="__('Placed until')" type="date" :value="$filters['to'] ?? ''" />
        <button type="submit">{{ __('Filter') }}</button>
    </form>
    @can('orders.export')
        <form id="bulk-orders" method="post" action="{{ route('admin.bulk.orders.export') }}" class="filters" aria-label="{{ __('Export orders') }}">
            @csrf
            <input type="hidden" name="filter_status" value="{{ $filters['status'] ?? '' }}">
            @foreach (['q', 'buyer', 'seller', 'from', 'to'] as $key)
                <input type="hidden" name="filter_{{ $key }}" value="{{ $filters[$key] ?? '' }}">
            @endforeach
            <x-select name="scope" id="bulk-scope" :label="__('Export to CSV')" :options="['selected' => __('Ticked orders'), 'filtered' => __('All orders matching the filter (:count)', ['count' => $orders->total()])]" />
            <button type="submit" class="btn-secondary">{{ __('Download CSV') }}</button>
        </form>
    @endcan
    @include('admin.orders.table', ['orders' => $orders, 'selectable' => auth()->user()->can('orders.export')])
    {{ $orders->links() }}
@endsection
