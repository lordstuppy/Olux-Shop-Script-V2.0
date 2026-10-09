<ul class="nav">
    @foreach ([
        ['admin.dashboard', __('Overview'), 'staff.dashboard'],
        ['admin.orders.index', __('Orders'), 'orders.view'],
        ['admin.payments.index', __('Payments'), 'payments.view'],
        ['admin.webhooks.index', __('Webhooks'), 'webhooks.manage'],
        ['admin.products.index', __('Products'), 'products.manage'],
        ['admin.categories.index', __('Categories'), 'categories.manage'],
        ['admin.users.index', __('Users'), 'users.view'],
        ['admin.sellers.index', __('Seller applications'), 'sellers.manage'],
        ['admin.payouts.index', __('Payouts'), 'payouts.manage'],
        ['admin.reconciliation', __('Reconciliation'), 'payouts.manage'],
        ['admin.commission.index', __('Commission'), 'commission.manage'],
        ['admin.coupons.index', __('Coupons'), 'coupons.manage'],
        ['admin.gift-cards.index', __('Gift cards'), 'giftcards.manage'],
        ['admin.rates.index', __('Exchange rates'), 'rates.manage'],
        ['admin.tickets.index', __('Tickets'), 'tickets.manage'],
        ['admin.reviews.index', __('Reviews'), 'reviews.moderate'],
        ['admin.reports', __('Reports'), 'reports.view'],
        ['admin.exports.index', __('Exports'), 'exports.download'],
        ['admin.announcements.index', __('Announcements'), 'announcements.manage'],
        ['admin.settings.index', __('Settings'), 'settings.manage'],
        ['admin.audit.index', __('Audit log'), 'audit.view'],
    ] as [$routeName, $label, $ability])
        @can($ability)
            <li><a href="{{ route($routeName) }}" @if(request()->routeIs($routeName)) aria-current="page" @endif>{{ $label }}</a></li>
        @endcan
    @endforeach
</ul>
