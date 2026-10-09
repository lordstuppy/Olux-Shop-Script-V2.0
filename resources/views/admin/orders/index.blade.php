@extends('layouts.app')

@section('title', __('Orders - Admin'))
@section('noindex', true)
@section('subnav') @include('partials.admin-nav') @endsection

@section('content')
    <h1>{{ __('Orders') }}</h1>
    <form class="filters" method="get">
        <x-field name="q" :label="__('Order id starts with')" :value="$filters['q'] ?? ''" maxlength="64" />
        <x-select name="status" :label="__('Status')" :options="collect(\App\Enums\OrderStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all()" :value="$filters['status'] ?? ''" :placeholder="__('Any status')" />
        <button type="submit">{{ __('Filter') }}</button>
    </form>
    @can('orders.export')
        <form id="bulk-orders" method="post" action="{{ route('admin.bulk.orders.export') }}" class="filters" aria-label="{{ __('Export orders') }}">
            @csrf
            <input type="hidden" name="filter_status" value="{{ $filters['status'] ?? '' }}">
            <input type="hidden" name="filter_q" value="{{ $filters['q'] ?? '' }}">
            <x-select name="scope" id="bulk-scope" :label="__('Export to CSV')" :options="['selected' => __('Ticked orders'), 'filtered' => __('All orders matching the filter (:count)', ['count' => $orders->total()])]" />
            <button type="submit" class="btn-secondary">{{ __('Download CSV') }}</button>
        </form>
    @endcan
    @include('admin.orders.table', ['orders' => $orders, 'selectable' => auth()->user()->can('orders.export')])
    {{ $orders->links() }}
@endsection
