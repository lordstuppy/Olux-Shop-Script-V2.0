<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @if (($theme ?? 'system') !== 'system') data-theme="{{ $theme }}" @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ trim($__env->yieldContent('title')) ? trim($__env->yieldContent('title')).' - ' : '' }}{{ config('app.name') }}</title>
    <meta name="description" content="@yield('description', __('Digital tools, tutorials, licences and subscriptions from independent sellers.'))">
    <link rel="canonical" href="@yield('canonical', url()->current())">
    @hasSection('noindex')
        <meta name="robots" content="noindex">
    @endif
    <link rel="stylesheet" href="{{ static_asset('css/app.css') }}">
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    @stack('head')
    {{-- Optional enhancement only; the site works with scripts blocked. --}}
    <script src="{{ static_asset('js/enhance.js') }}" defer></script>
</head>
<body data-label-show-password="{{ __('Show password') }}" data-label-hide-password="{{ __('Hide password') }}"
    @auth
        data-live="{{ route('live') }}" data-live-version="{{ $liveVersion ?? '' }}" data-live-sound="{{ auth()->user()->notification_sound !== false ? '1' : '0' }}"
        data-label-live-lost="{{ __('Connection lost. Trying again...') }}" data-label-live-back="{{ __('Connection restored.') }}"
        data-label-live-updated="{{ __('This page was updated.') }}" data-label-live-pending="{{ __('This page has new information.') }}"
        data-label-live-show="{{ __('Show it') }}" data-label-live-new="{{ __('New notification:') }}" data-label-close="{{ __('Close') }}"
    @endauth
>
<a class="skip-link" href="#main">{{ __('Skip to main content') }}</a>

<header class="site-header">
    <div class="wrap">
        <a class="brand" href="{{ route('home') }}">{{ config('app.name') }}</a>

        <form class="search" action="{{ route('products.index') }}" method="get" role="search">
            <label class="visually-hidden" for="site-search">{{ __('Search products') }}</label>
            <input type="search" id="site-search" name="q" value="{{ request()->routeIs('products.index') ? request('q') : '' }}" placeholder="{{ __('Search products') }}" maxlength="100" data-live-search="{{ route('search.suggest') }}">
            <button type="submit">{{ __('Search') }}</button>
        </form>

        <nav aria-label="{{ __('Main') }}">
            <ul class="nav">
                <li><a href="{{ route('products.index') }}" @if(request()->routeIs('products.*')) aria-current="page" @endif>{{ __('Products') }}</a></li>
                <li><a href="{{ route('cart.show') }}" @if(request()->routeIs('cart.*')) aria-current="page" @endif>{{ __('Cart') }} <span class="badge" aria-label="{{ __(':count items in cart', ['count' => $cartCount]) }}">{{ $cartCount }}</span></a></li>
                @auth
                    <li><a href="{{ route('account.orders') }}" @if(request()->routeIs('account.orders', 'orders.*')) aria-current="page" @endif>{{ __('Orders') }}</a></li>
                    <li><a href="{{ route('notifications.index') }}" @if(request()->routeIs('notifications.*')) aria-current="page" @endif>{{ __('Notifications') }} <span class="badge" data-live-unread @if (! $unreadCount) hidden @endif><span data-live-unread-count>{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span><span class="visually-hidden"> {{ __('unread') }}</span></span></a></li>
                    <li><a href="{{ route('wallet.show') }}" @if(request()->routeIs('wallet.*')) aria-current="page" @endif>{{ __('Wallet') }} (<span data-live-balance>{{ money(auth()->user()->balance_minor, auth()->user()->currency) }}</span>)</a></li>
                    <li><a href="{{ route('wishlist.index') }}" @if(request()->routeIs('wishlist.*')) aria-current="page" @endif>{{ __('Wishlist') }}</a></li>
                    <li><a href="{{ route('tickets.index') }}" @if(request()->routeIs('tickets.*')) aria-current="page" @endif>{{ __('Support') }}</a></li>
                    @if (auth()->user()->isSeller())
                        <li><a href="{{ route('seller.dashboard') }}" @if(request()->routeIs('seller.*') && ! request()->routeIs('seller.apply*')) aria-current="page" @endif>{{ __('Seller') }}</a></li>
                    @endif
                    @can('staff.dashboard')
                        <li><a href="{{ route('admin.dashboard') }}" @if(request()->routeIs('admin.*')) aria-current="page" @endif>{{ __('Admin') }}</a></li>
                    @endcan
                    <li><a href="{{ route('account.settings') }}" @if(request()->routeIs('account.settings')) aria-current="page" @endif>{{ __('Account') }}</a></li>
                    <li>
                        <form class="inline" method="post" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="btn-link">{{ __('Sign out') }}</button>
                        </form>
                    </li>
                @else
                    <li><a href="{{ route('login') }}" @if(request()->routeIs('login')) aria-current="page" @endif>{{ __('Sign in') }}</a></li>
                    <li><a href="{{ route('register') }}" @if(request()->routeIs('register')) aria-current="page" @endif>{{ __('Create account') }}</a></li>
                @endauth
            </ul>
        </nav>
    </div>
</header>

@hasSection('subnav')
    <nav class="subnav" aria-label="{{ __('Section') }}">
        <div class="wrap">@yield('subnav')</div>
    </nav>
@endif

<main id="main" tabindex="-1">
    <div class="wrap">
        @foreach ($announcements ?? [] as $announcement)
            <aside class="announcement" aria-label="{{ __('Announcement') }}">
                <strong>{{ $announcement['title'] }}</strong> {{ $announcement['body'] }}
            </aside>
        @endforeach
        @include('partials.flash')
        <div id="live-content">
            @yield('content')
        </div>
    </div>
</main>

<footer class="site-footer">
    <div class="wrap">
        <ul class="nav">
            <li><a href="{{ route('pages.terms') }}">{{ __('Terms of service') }}</a></li>
            <li><a href="{{ route('pages.privacy') }}">{{ __('Privacy policy') }}</a></li>
            <li><a href="{{ route('pages.retention') }}">{{ __('Data retention') }}</a></li>
            <li><a href="{{ route('seller.apply') }}">{{ __('Sell with us') }}</a></li>
            <li><a href="mailto:{{ config('shop.support_email') }}">{{ config('shop.support_email') }}</a></li>
        </ul>
        <form method="post" action="{{ route('cart.currency') }}" class="actions">
            @csrf
            <label for="footer-currency">{{ __('Shop currency') }}</label>
            <select id="footer-currency" name="currency">
                @foreach (\App\Support\Money::supported() as $code)
                    <option value="{{ $code }}" @selected($code === $shopCurrency)>{{ $code }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn-secondary">{{ __('Change currency') }}</button>
        </form>
        <form method="post" action="{{ route('theme') }}" class="actions">
            @csrf
            <label for="footer-theme">{{ __('Colour scheme') }}</label>
            <select id="footer-theme" name="theme">
                <option value="system" @selected($theme === 'system')>{{ __('Same as device') }}</option>
                <option value="light" @selected($theme === 'light')>{{ __('Light') }}</option>
                <option value="dark" @selected($theme === 'dark')>{{ __('Dark') }}</option>
            </select>
            <button type="submit" class="btn-secondary">{{ __('Apply') }}</button>
        </form>
    </div>
</footer>
</body>
</html>
