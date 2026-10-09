<ul class="nav">
    @foreach ([
        'admin.dashboard' => 'Overview',
        'admin.orders.index' => 'Orders',
        'admin.payments.index' => 'Payments',
        'admin.webhooks.index' => 'Webhooks',
        'admin.products.index' => 'Products',
        'admin.categories.index' => 'Categories',
        'admin.users.index' => 'Users',
        'admin.sellers.index' => 'Seller applications',
        'admin.payouts.index' => 'Payouts',
        'admin.reconciliation' => 'Reconciliation',
        'admin.coupons.index' => 'Coupons',
        'admin.gift-cards.index' => 'Gift cards',
        'admin.rates.index' => 'Exchange rates',
        'admin.tickets.index' => 'Tickets',
        'admin.audit.index' => 'Audit log',
    ] as $routeName => $label)
        <li><a href="{{ route($routeName) }}" @if(request()->routeIs($routeName)) aria-current="page" @endif>{{ $label }}</a></li>
    @endforeach
</ul>
