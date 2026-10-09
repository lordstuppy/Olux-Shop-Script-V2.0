@extends('layouts.minimal')

@section('title', __('Too many requests'))

@section('content')
    <h1>{{ __('Too many requests') }}</h1>
    <p>{{ $reason ?? __('You sent too many requests in a short time. Wait a minute and try again.') }}</p>
@endsection
