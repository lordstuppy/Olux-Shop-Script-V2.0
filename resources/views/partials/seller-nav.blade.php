<ul class="nav">
    @foreach ([
        'seller.dashboard' => 'Overview',
        'seller.products.index' => 'Products',
        'seller.sales' => 'Sales',
        'seller.payouts' => 'Payouts',
        'seller.payout-settings' => 'Payout settings',
    ] as $routeName => $label)
        <li><a href="{{ route($routeName) }}" @if(request()->routeIs($routeName)) aria-current="page" @endif>{{ $label }}</a></li>
    @endforeach
</ul>
