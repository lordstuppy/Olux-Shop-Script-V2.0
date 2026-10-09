// Pages every role must be able to load. Paths with ":product" are resolved from the catalog.
module.exports = {
    guest: ['/', '/products', '/products?q=guide&sort=price_asc', ':product', '/cart', '/login', '/register', '/forgot-password', '/terms', '/privacy', '/data-retention', '/sell'],
    buyer: ['/orders', '/wallet', '/wishlist', '/account', '/account/two-factor', '/tickets', '/tickets/new', '/cart', '/sell', '/disputes', '/products?price_min=5&price_max=30&seller=2'],
    seller: ['/seller', '/seller/products', '/seller/products/new', '/seller/products/1/edit', '/seller/sales', '/seller/payouts', '/seller/payout-settings', '/disputes', '/disputes/1'],
    admin: ['/admin', '/admin/users', '/admin/sellers', '/admin/products', '/admin/categories', '/admin/orders', '/admin/payments', '/admin/webhooks', '/admin/payouts', '/admin/reconciliation', '/admin/coupons', '/admin/gift-cards', '/admin/exchange-rates', '/admin/tickets', '/admin/audit', '/admin/reports', '/admin/exports', '/admin/settings', '/admin/announcements', '/admin/products/1', '/admin/users/2', '/admin/reviews', '/products?sort=best', '/admin/commission', '/admin/disputes', '/disputes/1', '/admin/gateway', '/admin/email-templates', '/admin/email-templates/order_paid', '/admin/health'],
    accounts: {
        buyer: 'buyer@example.test',
        seller: 'seller@example.test',
        admin: 'admin@example.test',
    },
    password: 'correct-horse-battery-1',
};
