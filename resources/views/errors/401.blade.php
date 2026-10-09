@extends('layouts.minimal')

@section('title', __('Sign-in required'))

@section('content')
    <h1>{{ __('Sign-in required') }}</h1>
    <p>{{ __('You need to sign in to see this page.') }}</p>
@endsection
