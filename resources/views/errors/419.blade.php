@extends('layouts.minimal')

@section('title', __('Form expired'))

@section('content')
    <h1>{{ __('Form expired') }}</h1>
    <p>{{ __('Nothing was changed. The form had expired, usually because the page was open for a long time or you were signed out in the meantime.') }}</p>
    <ol>
        <li>{{ __('Go back with your browser and copy anything you typed that you want to keep.') }}</li>
        @guest
            <li>{{ __('You are signed out:') }} <a href="{{ route('login') }}" target="_blank" rel="noopener">{{ __('sign in again in a new tab') }}</a>.</li>
        @endguest
        <li>{{ __('Reload the form page and send the form again.') }}</li>
    </ol>
@endsection
