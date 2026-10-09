@extends('layouts.app')

@section('title', __('Exports - Admin'))
@section('noindex', true)
@section('subnav') @include('partials.admin-nav') @endsection

@section('content')
    <h1>{{ __('CSV exports') }}</h1>
    <p>{{ __('Exports for accounting. Amounts are decimal strings in the row\'s currency. Every download is recorded in the audit log.') }}</p>
    <form method="get" action="{{ route('admin.exports.download') }}" class="stack">
        <x-select name="type" :label="__('Data')" :options="$types" />
        <x-field name="from" :label="__('From')" type="date" :value="now()->startOfMonth()->toDateString()" required />
        <x-field name="to" :label="__('To')" type="date" :value="now()->toDateString()" required />
        <button type="submit">{{ __('Download CSV') }}</button>
    </form>
@endsection
