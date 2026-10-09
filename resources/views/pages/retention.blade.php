@extends('layouts.app')

@section('title', __('Data retention policy'))

@section('content')
    <h1>{{ __('Data retention policy') }}</h1>
    <div class="flash flash-info" role="status">
        {{ __('Placeholder. The operator of this shop must replace this page with text reviewed for their jurisdiction before going live.') }}
    </div>
    <p>{{ __('Questions:') }} <a href="mailto:{{ config('shop.support_email') }}">{{ config('shop.support_email') }}</a>.</p>
@endsection
