@extends('layouts.minimal')

@section('title', __('Form expired'))

@section('content')
    <h1>{{ __('Form expired') }}</h1>
    <p>{{ __('This form expired or its security token was missing, so nothing was changed. Go back, reload the page and submit the form again.') }}</p>
@endsection
