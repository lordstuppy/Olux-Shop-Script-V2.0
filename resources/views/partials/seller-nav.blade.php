<ul class="nav">
    @foreach ([
        'seller.dashboard' => __('Overview'),
        'seller.products.index' => __('Products'),
        'seller.sales' => __('Sales'),
        'seller.payouts' => __('Payouts'),
        'seller.payout-settings' => __('Payout settings'),
    ] as $routeName => $label)
        <li><a href="{{ route($routeName) }}" @if(request()->routeIs($routeName)) aria-current="page" @endif>{{ $label }}</a></li>
    @endforeach
</ul>
