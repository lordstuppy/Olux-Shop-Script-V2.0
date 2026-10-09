@extends('layouts.minimal')

@section('title', __('Page not found'))

@section('content')
    <h1>{{ __('Page not found') }}</h1>
    @php
        // Only messages written by the application are shown; framework messages may reveal internals.
        $detail = isset($exception) ? $exception->getMessage() : '';
        $generic = $detail === '' || $detail === 'This action is unauthorized.' || str_starts_with($detail, 'No query results') || str_starts_with($detail, 'The route ') || str_contains($detail, '\\');
    @endphp
    <p>{{ ! $generic ? $detail : __('The page you asked for does not exist or is no longer available.') }}</p>
@endsection
