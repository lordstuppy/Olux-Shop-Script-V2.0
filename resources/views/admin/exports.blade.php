@extends('layouts.app')

@section('title', 'Exports - Admin')
@section('noindex', true)
@section('subnav') @include('partials.admin-nav') @endsection

@section('content')
    <h1>CSV exports</h1>
    <p>Exports for accounting. Amounts are decimal strings in the row's currency. Every download is recorded in the audit log.</p>
    <form method="get" action="{{ route('admin.exports.download') }}" class="stack">
        <x-select name="type" label="Data" :options="$types" />
        <x-field name="from" label="From" type="date" :value="now()->startOfMonth()->toDateString()" required />
        <x-field name="to" label="To" type="date" :value="now()->toDateString()" required />
        <button type="submit">Download CSV</button>
    </form>
@endsection
