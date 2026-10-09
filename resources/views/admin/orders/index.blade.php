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
    @include('admin.orders.table', ['orders' => $orders])
    {{ $orders->links() }}
@endsection
