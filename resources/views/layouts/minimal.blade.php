<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') - {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
<header class="site-header">
    <div class="wrap"><a class="brand" href="{{ url('/') }}">{{ config('app.name') }}</a></div>
</header>
<main id="main">
    <div class="wrap">
        @yield('content')
        <p class="muted mt">{{ __('Request id:') }} <span class="mono">{{ app(\App\Support\RequestId::class)->get() }}</span>. {{ __('Quote it if you contact') }} <a href="mailto:{{ config('shop.support_email') }}">{{ config('shop.support_email') }}</a>.</p>
        <p><a href="{{ url('/') }}">{{ __('Back to the shop') }}</a></p>
    </div>
</main>
</body>
</html>
