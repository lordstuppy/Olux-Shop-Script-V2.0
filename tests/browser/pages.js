// Pages every role must be able to load. Paths with ":product" are resolved from the catalog.
module.exports = {
    guest: ['/', '/products', '/products?q=guide&sort=price_asc', ':product', '/cart', '/login', '/register', '/forgot-password', '/terms', '/privacy', '/data-retention', '/sell'],
    buyer: ['/orders', '/wallet', '/account', '/tickets', '/tickets/new', '/cart', '/sell'],
    seller: ['/seller', '/seller/products', '/seller/products/new', '/seller/sales', '/seller/payouts'],
    admin: ['/admin', '/admin/users', '/admin/sellers', '/admin/products', '/admin/categories', '/admin/orders', '/admin/payments', '/admin/webhooks', '/admin/payouts', '/admin/reconciliation', '/admin/coupons', '/admin/gift-cards', '/admin/exchange-rates', '/admin/tickets', '/admin/audit'],
    accounts: {
        buyer: 'buyer@example.test',
        seller: 'seller@example.test',
        admin: 'admin@example.test',
    },
    password: 'correct-horse-battery-1',
};
