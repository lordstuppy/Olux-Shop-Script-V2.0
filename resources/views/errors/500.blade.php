@extends('layouts.minimal')

@section('title', __('Something went wrong'))

@section('content')
    <h1>{{ __('Something went wrong') }}</h1>
    <p>{{ __('An unexpected error occurred. It has been logged and nothing about it is shown here. Please try again in a few minutes.') }}</p>
@endsection
