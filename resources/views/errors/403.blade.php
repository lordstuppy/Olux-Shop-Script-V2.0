@extends('layouts.minimal')

@section('title', 'Access denied')

@section('content')
    <h1>Access denied</h1>
    @php
        // Only messages written by the application are shown; framework messages may reveal internals.
        $detail = isset($exception) ? $exception->getMessage() : '';
        $generic = $detail === '' || $detail === 'This action is unauthorized.' || str_starts_with($detail, 'No query results') || str_starts_with($detail, 'The route ') || str_contains($detail, '\\');
    @endphp
    <p>{{ ! $generic ? $detail : 'Your account does not have access to this page.' }}</p>
@endsection
