@extends('layouts.minimal')

@section('title', __('Down for maintenance'))

@section('content')
    <h1>{{ __('Down for maintenance') }}</h1>
    <p>{{ __('The shop is temporarily unavailable for maintenance. Please try again shortly.') }}</p>
@endsection
