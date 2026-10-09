<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\DownloadController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\Seller;
use App\Http\Controllers\SellerApplicationController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

/*
| Every state-changing route uses POST, PUT or DELETE and is CSRF-protected,
| except the Shkeeper webhook, which is authenticated by its HMAC signature.
*/

// Public catalog and pages
Route::get('/', HomeController::class)->name('home');
Route::get('/products', [ProductController::class, 'index'])->name('products.index');
Route::get('/products/{slug}', [ProductController::class, 'show'])->name('products.show');
Route::get('/search/suggest', [ProductController::class, 'suggest'])->middleware('throttle:search')->name('search.suggest');

Route::get('/terms', [PageController::class, 'terms'])->name('pages.terms');
Route::get('/privacy', [PageController::class, 'privacy'])->name('pages.privacy');
Route::get('/data-retention', [PageController::class, 'retention'])->name('pages.retention');
Route::get('/sitemap.xml', [PageController::class, 'sitemap'])->name('sitemap');
Route::get('/robots.txt', [PageController::class, 'robots'])->name('robots');

Route::get('/health', [HealthController::class, 'health'])->name('health');
Route::get('/metrics', [HealthController::class, 'metrics'])->name('metrics');

// Cart (works for guests; stored in the session)
Route::get('/cart', [CartController::class, 'show'])->name('cart.show');
Route::middleware('throttle:forms')->group(function () {
    Route::post('/cart/items', [CartController::class, 'add'])->name('cart.add');
    Route::put('/cart/items/{product:id}', [CartController::class, 'update'])->name('cart.update');
    Route::delete('/cart/items/{product:id}', [CartController::class, 'remove'])->name('cart.remove');
    Route::post('/cart/currency', [CartController::class, 'currency'])->name('cart.currency');
});

// Payment provider callback
Route::post('/webhooks/shkeeper', [WebhookController::class, 'shkeeper'])
    ->middleware('throttle:webhook')
    ->name('webhooks.shkeeper');

// Guests
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');
    Route::get('/forgot-password', [PasswordResetController::class, 'showRequest'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'sendLink'])->middleware('throttle:password-reset')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'showReset'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:password-reset')->name('password.update');
});

// Signed-in users
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/checkout', [CheckoutController::class, 'show'])->name('checkout.show');
    Route::post('/checkout/coupon', [CheckoutController::class, 'applyCoupon'])->middleware('throttle:forms')->name('checkout.coupon');
    Route::delete('/checkout/coupon', [CheckoutController::class, 'removeCoupon'])->name('checkout.coupon.remove');
    Route::post('/checkout', [CheckoutController::class, 'store'])->middleware('throttle:checkout')->name('checkout.store');

    Route::get('/orders', [OrderController::class, 'index'])->name('account.orders');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::get('/orders/{order}/pay', [OrderController::class, 'pay'])->name('orders.pay');
    Route::post('/orders/{order}/pay', [OrderController::class, 'startPayment'])->middleware('throttle:checkout')->name('orders.pay.start');
    Route::post('/orders/{order}/pay-balance', [OrderController::class, 'payWithBalance'])->middleware('throttle:checkout')->name('orders.pay.balance');
    Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');
    Route::get('/orders/{order}/result', [OrderController::class, 'result'])->name('orders.result');
    Route::get('/orders/{order}/invoice', [OrderController::class, 'invoice'])->name('orders.invoice');
    Route::get('/orders/{order}/items/{item}/files/{file}', DownloadController::class)
        ->middleware('signed')->name('orders.download');

    Route::get('/wallet', [WalletController::class, 'show'])->name('wallet.show');
    Route::post('/wallet/redeem', [WalletController::class, 'redeem'])->middleware('throttle:redeem')->name('wallet.redeem');

    Route::get('/account', [AccountController::class, 'show'])->name('account.settings');
    Route::put('/account/profile', [AccountController::class, 'updateProfile'])->name('account.profile');
    Route::put('/account/password', [AccountController::class, 'updatePassword'])->middleware('throttle:login')->name('account.password');

    Route::get('/tickets', [TicketController::class, 'index'])->name('tickets.index');
    Route::get('/tickets/new', [TicketController::class, 'create'])->name('tickets.create');
    Route::post('/tickets', [TicketController::class, 'store'])->middleware('throttle:forms')->name('tickets.store');
    Route::get('/tickets/{ticket}', [TicketController::class, 'show'])->name('tickets.show');
    Route::post('/tickets/{ticket}/messages', [TicketController::class, 'reply'])->middleware('throttle:forms')->name('tickets.reply');
    Route::post('/tickets/{ticket}/close', [TicketController::class, 'close'])->name('tickets.close');

    Route::get('/sell', [SellerApplicationController::class, 'show'])->name('seller.apply');
    Route::post('/sell', [SellerApplicationController::class, 'store'])->middleware('throttle:forms')->name('seller.apply.store');

    // Sellers
    Route::middleware('role:seller')->prefix('seller')->name('seller.')->group(function () {
        Route::get('/', Seller\DashboardController::class)->name('dashboard');
        Route::get('/products', [Seller\ProductController::class, 'index'])->name('products.index');
        Route::get('/products/new', [Seller\ProductController::class, 'create'])->name('products.create');
        Route::post('/products', [Seller\ProductController::class, 'store'])->name('products.store');
        Route::get('/products/{product:id}/edit', [Seller\ProductController::class, 'edit'])->name('products.edit');
        Route::put('/products/{product:id}', [Seller\ProductController::class, 'update'])->name('products.update');
        Route::post('/products/{product:id}/submit', [Seller\ProductController::class, 'submit'])->name('products.submit');
        Route::post('/products/{product:id}/files', [Seller\ProductController::class, 'uploadFile'])->name('products.files.store');
        Route::delete('/products/{product:id}/files/{file}', [Seller\ProductController::class, 'deleteFile'])->name('products.files.destroy');
        Route::post('/products/{product:id}/keys', [Seller\ProductController::class, 'addKeys'])->name('products.keys.store');
        Route::get('/sales', [Seller\SalesController::class, 'index'])->name('sales');
        Route::post('/items/{item}/deliver', [Seller\SalesController::class, 'deliver'])->name('items.deliver');
        Route::get('/payouts', [Seller\PayoutController::class, 'index'])->name('payouts');
        Route::post('/payouts', [Seller\PayoutController::class, 'store'])->middleware('throttle:forms')->name('payouts.store');
    });

    // Administrators
    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/', Admin\DashboardController::class)->name('dashboard');
        Route::get('/users', [Admin\UserController::class, 'index'])->name('users.index');
        Route::get('/users/{user}', [Admin\UserController::class, 'show'])->name('users.show');
        Route::post('/users/{user}/role', [Admin\UserController::class, 'role'])->name('users.role');
        Route::post('/users/{user}/status', [Admin\UserController::class, 'status'])->name('users.status');
        Route::get('/sellers', [Admin\SellerController::class, 'index'])->name('sellers.index');
        Route::post('/sellers/{profile}/approve', [Admin\SellerController::class, 'approve'])->name('sellers.approve');
        Route::post('/sellers/{profile}/reject', [Admin\SellerController::class, 'reject'])->name('sellers.reject');
        Route::get('/products', [Admin\ProductController::class, 'index'])->name('products.index');
        Route::post('/products/{product:id}/status', [Admin\ProductController::class, 'status'])->name('products.status');
        Route::get('/categories', [Admin\CategoryController::class, 'index'])->name('categories.index');
        Route::post('/categories', [Admin\CategoryController::class, 'store'])->name('categories.store');
        Route::get('/orders', [Admin\OrderController::class, 'index'])->name('orders.index');
        Route::get('/orders/{order}', [Admin\OrderController::class, 'show'])->name('orders.show');
        Route::post('/orders/{order}/refunds', [Admin\OrderController::class, 'refund'])->name('orders.refund');
        Route::get('/payments', [Admin\PaymentController::class, 'index'])->name('payments.index');
        Route::get('/webhooks', [Admin\WebhookEventController::class, 'index'])->name('webhooks.index');
        Route::post('/webhooks/{event}/retry', [Admin\WebhookEventController::class, 'retry'])->name('webhooks.retry');
        Route::get('/payouts', [Admin\PayoutController::class, 'index'])->name('payouts.index');
        Route::post('/payouts/{payout}/approve', [Admin\PayoutController::class, 'approve'])->name('payouts.approve');
        Route::post('/payouts/{payout}/paid', [Admin\PayoutController::class, 'paid'])->name('payouts.paid');
        Route::post('/payouts/{payout}/reject', [Admin\PayoutController::class, 'reject'])->name('payouts.reject');
        Route::get('/coupons', [Admin\CouponController::class, 'index'])->name('coupons.index');
        Route::post('/coupons', [Admin\CouponController::class, 'store'])->name('coupons.store');
        Route::post('/coupons/{coupon}/toggle', [Admin\CouponController::class, 'toggle'])->name('coupons.toggle');
        Route::get('/gift-cards', [Admin\GiftCardController::class, 'index'])->name('gift-cards.index');
        Route::post('/gift-cards', [Admin\GiftCardController::class, 'store'])->name('gift-cards.store');
        Route::get('/exchange-rates', [Admin\ExchangeRateController::class, 'index'])->name('rates.index');
        Route::post('/exchange-rates', [Admin\ExchangeRateController::class, 'store'])->name('rates.store');
        Route::get('/tickets', [Admin\TicketController::class, 'index'])->name('tickets.index');
        Route::get('/audit', [Admin\AuditLogController::class, 'index'])->name('audit.index');
        Route::get('/reconciliation', Admin\ReconciliationController::class)->name('reconciliation');
    });
});
