@extends('layouts.app')

@section('title', 'Orders - Admin')
@section('noindex', true)
@section('subnav') @include('partials.admin-nav') @endsection

@section('content')
    <h1>Orders</h1>
    <form class="filters" method="get">
        <x-field name="q" label="Order id starts with" :value="$filters['q'] ?? ''" maxlength="64" />
        <x-select name="status" label="Status" :options="collect(\App\Enums\OrderStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all()" :value="$filters['status'] ?? ''" placeholder="Any status" />
        <button type="submit">Filter</button>
    </form>
    @include('admin.orders.table', ['orders' => $orders])
    {{ $orders->links() }}
@endsection
