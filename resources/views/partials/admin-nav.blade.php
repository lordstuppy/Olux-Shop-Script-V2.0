<ul class="nav">
    @foreach ([
        ['admin.dashboard', 'Overview', 'staff.dashboard'],
        ['admin.orders.index', 'Orders', 'orders.view'],
        ['admin.payments.index', 'Payments', 'payments.view'],
        ['admin.webhooks.index', 'Webhooks', 'webhooks.manage'],
        ['admin.products.index', 'Products', 'products.manage'],
        ['admin.categories.index', 'Categories', 'categories.manage'],
        ['admin.users.index', 'Users', 'users.view'],
        ['admin.sellers.index', 'Seller applications', 'sellers.manage'],
        ['admin.payouts.index', 'Payouts', 'payouts.manage'],
        ['admin.reconciliation', 'Reconciliation', 'payouts.manage'],
        ['admin.coupons.index', 'Coupons', 'coupons.manage'],
        ['admin.gift-cards.index', 'Gift cards', 'giftcards.manage'],
        ['admin.rates.index', 'Exchange rates', 'rates.manage'],
        ['admin.tickets.index', 'Tickets', 'tickets.manage'],
        ['admin.reviews.index', 'Reviews', 'reviews.moderate'],
        ['admin.reports', 'Reports', 'reports.view'],
        ['admin.exports.index', 'Exports', 'exports.download'],
        ['admin.announcements.index', 'Announcements', 'announcements.manage'],
        ['admin.settings.index', 'Settings', 'settings.manage'],
        ['admin.audit.index', 'Audit log', 'audit.view'],
    ] as [$routeName, $label, $ability])
        @can($ability)
            <li><a href="{{ route($routeName) }}" @if(request()->routeIs($routeName)) aria-current="page" @endif>{{ $label }}</a></li>
        @endcan
    @endforeach
</ul>
