@extends('layouts.minimal')

@section('title', __('Not allowed'))

@section('content')
    <h1>{{ __('Not allowed') }}</h1>
    <p>{{ __('This address does not accept that kind of request.') }}</p>
@endsection
