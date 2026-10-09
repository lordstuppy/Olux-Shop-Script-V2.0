@extends('layouts.minimal')

@section('title', __('Access denied'))

@section('content')
    <h1>{{ __('Access denied') }}</h1>
    @php
        // Only messages written by the application are shown; framework messages may reveal internals.
        $detail = isset($exception) ? $exception->getMessage() : '';
        $generic = $detail === '' || $detail === 'This action is unauthorized.' || str_starts_with($detail, 'No query results') || str_starts_with($detail, 'The route ') || str_contains($detail, '\\');
    @endphp
    <p>{{ ! $generic ? $detail : __('Your account does not have access to this page.') }}</p>
@endsection
