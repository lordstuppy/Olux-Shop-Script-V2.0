<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\ConfirmPasswordController;
use App\Http\Controllers\DownloadController;
use App\Http\Controllers\EmailVerificationController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\PayoutCallbackController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductImageController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\Seller;
use App\Http\Controllers\SellerApplicationController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\TwoFactorController;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\WishlistController;
use Illuminate\Support\Facades\Route;

/*
| Every state-changing route uses POST, PUT or DELETE and is CSRF-protected,
| except the Shkeeper webhook, which is authenticated by its HMAC signature.
*/

// Public catalog and pages
Route::get('/', HomeController::class)->name('home');
Route::get('/products', [ProductController::class, 'index'])->name('products.index');
Route::get('/products/{slug}', [ProductController::class, 'show'])->name('products.show');
Route::get('/product-images/{image}/{size}', ProductImageController::class)->whereIn('size', ['thumb', 'large'])->name('products.image');
Route::get('/search/suggest', [ProductController::class, 'suggest'])->middleware('throttle:search')->name('search.suggest');

Route::get('/terms', [PageController::class, 'terms'])->name('pages.terms');
Route::get('/privacy', [PageController::class, 'privacy'])->name('pages.privacy');
Route::get('/data-retention', [PageController::class, 'retention'])->name('pages.retention');
Route::get('/sitemap.xml', [PageController::class, 'sitemap'])->name('sitemap');
Route::get('/robots.txt', [PageController::class, 'robots'])->name('robots');

Route::get('/sell', [SellerApplicationController::class, 'show'])->name('seller.apply');

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
Route::post('/webhooks/shkeeper/payouts', PayoutCallbackController::class)
    ->middleware('throttle:webhook')
    ->name('webhooks.shkeeper.payouts');

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
    Route::get('/two-factor-challenge', [TwoFactorController::class, 'challenge'])->name('two-factor.challenge');
    Route::post('/two-factor-challenge', [TwoFactorController::class, 'verifyChallenge'])->middleware('throttle:two-factor');
});

// Signed-in users
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/email/verify', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])->middleware(['signed', 'throttle:verification'])->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])->middleware('throttle:verification')->name('verification.send');

    Route::get('/confirm-password', [ConfirmPasswordController::class, 'show'])->name('password.confirm');
    Route::post('/confirm-password', [ConfirmPasswordController::class, 'store'])->middleware('throttle:login');

    Route::get('/account/two-factor', [TwoFactorController::class, 'show'])->name('account.two-factor');
    Route::post('/account/two-factor', [TwoFactorController::class, 'enable'])->middleware('throttle:two-factor')->name('account.two-factor.enable');
    Route::delete('/account/two-factor', [TwoFactorController::class, 'disable'])->middleware(['password.recent', 'throttle:two-factor'])->name('account.two-factor.disable');
    Route::post('/account/two-factor/recovery-codes', [TwoFactorController::class, 'regenerate'])->middleware('password.recent')->name('account.two-factor.recovery');
    Route::delete('/account/sessions/{handle}', [SessionController::class, 'destroy'])->name('account.sessions.destroy');
    Route::delete('/account/sessions', [SessionController::class, 'destroyOthers'])->middleware('password.recent')->name('account.sessions.destroy-others');

    Route::get('/checkout', [CheckoutController::class, 'show'])->middleware('verified')->name('checkout.show');
    Route::post('/checkout/coupon', [CheckoutController::class, 'applyCoupon'])->middleware(['verified', 'throttle:forms'])->name('checkout.coupon');
    Route::delete('/checkout/coupon', [CheckoutController::class, 'removeCoupon'])->name('checkout.coupon.remove');
    Route::post('/checkout', [CheckoutController::class, 'store'])->middleware(['verified', 'throttle:checkout'])->name('checkout.store');

    Route::get('/orders', [OrderController::class, 'index'])->name('account.orders');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::get('/orders/{order}/pay', [OrderController::class, 'pay'])->name('orders.pay');
    Route::post('/orders/{order}/pay', [OrderController::class, 'startPayment'])->middleware(['verified', 'throttle:checkout'])->name('orders.pay.start');
    Route::post('/orders/{order}/pay-balance', [OrderController::class, 'payWithBalance'])->middleware(['verified', 'throttle:checkout'])->name('orders.pay.balance');
    Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');
    Route::get('/orders/{order}/result', [OrderController::class, 'result'])->name('orders.result');
    Route::get('/orders/{order}/invoice', [OrderController::class, 'invoice'])->name('orders.invoice');
    Route::get('/orders/{order}/items/{item}/files/{file}', DownloadController::class)
        ->middleware('signed')->name('orders.download');

    Route::post('/products/{slug}/reviews', [ReviewController::class, 'store'])->middleware(['verified', 'throttle:forms'])->name('products.reviews.store');
    Route::get('/wishlist', [WishlistController::class, 'index'])->name('wishlist.index');
    Route::post('/wishlist', [WishlistController::class, 'store'])->middleware('throttle:forms')->name('wishlist.store');
    Route::delete('/wishlist/{product:id}', [WishlistController::class, 'destroy'])->name('wishlist.destroy');

    Route::post('/account/email', [AccountController::class, 'requestEmailChange'])->middleware('throttle:password-reset')->name('account.email');
    Route::get('/account/email/confirm/{token}', [AccountController::class, 'showEmailConfirm'])->name('account.email.confirm');
    Route::post('/account/email/confirm/{token}', [AccountController::class, 'confirmEmail'])->middleware('throttle:forms');

    Route::get('/wallet', [WalletController::class, 'show'])->name('wallet.show');
    Route::post('/wallet/redeem', [WalletController::class, 'redeem'])->middleware(['verified', 'throttle:redeem'])->name('wallet.redeem');

    Route::get('/account', [AccountController::class, 'show'])->name('account.settings');
    Route::put('/account/profile', [AccountController::class, 'updateProfile'])->name('account.profile');
    Route::put('/account/password', [AccountController::class, 'updatePassword'])->middleware('throttle:login')->name('account.password');

    Route::get('/tickets', [TicketController::class, 'index'])->name('tickets.index');
    Route::get('/tickets/new', [TicketController::class, 'create'])->name('tickets.create');
    Route::post('/tickets', [TicketController::class, 'store'])->middleware('throttle:forms')->name('tickets.store');
    Route::get('/tickets/{ticket}', [TicketController::class, 'show'])->name('tickets.show');
    Route::post('/tickets/{ticket}/messages', [TicketController::class, 'reply'])->middleware('throttle:forms')->name('tickets.reply');
    Route::post('/tickets/{ticket}/close', [TicketController::class, 'close'])->name('tickets.close');
    Route::post('/tickets/{ticket}/assign', [TicketController::class, 'assign'])->middleware(['role:admin,support', 'staff.2fa'])->name('tickets.assign');

    Route::post('/sell', [SellerApplicationController::class, 'store'])->middleware(['verified', 'throttle:forms'])->name('seller.apply.store');

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
        Route::post('/products/{product:id}/images', [Seller\ProductController::class, 'uploadImage'])->name('products.images.store');
        Route::delete('/products/{product:id}/images/{image}', [Seller\ProductController::class, 'deleteImage'])->name('products.images.destroy');
        Route::get('/sales', [Seller\SalesController::class, 'index'])->name('sales');
        Route::post('/items/{item}/deliver', [Seller\SalesController::class, 'deliver'])->name('items.deliver');
        Route::get('/payouts', [Seller\PayoutController::class, 'index'])->name('payouts');
        Route::post('/payouts', [Seller\PayoutController::class, 'store'])->middleware('throttle:forms')->name('payouts.store');
        Route::get('/payout-settings', [Seller\PayoutSettingsController::class, 'show'])->name('payout-settings');
        Route::post('/payout-settings', [Seller\PayoutSettingsController::class, 'requestChange'])->middleware(['password.recent', 'throttle:forms'])->name('payout-settings.store');
        Route::get('/payout-address/confirm/{token}', [Seller\PayoutSettingsController::class, 'showConfirm'])->name('payout-address.confirm');
        Route::post('/payout-address/confirm/{token}', [Seller\PayoutSettingsController::class, 'confirm'])->middleware('throttle:forms');
    });

    // Staff: admin, finance and support, each limited by App\Support\Permissions.
    // Two-factor authentication is required; sensitive actions need a recent password.
    Route::middleware(['role:admin,finance,support', 'staff.2fa'])->prefix('admin')->name('admin.')->group(function () {
        Route::get('/', Admin\DashboardController::class)->can('staff.dashboard')->name('dashboard');

        Route::get('/users', [Admin\UserController::class, 'index'])->can('users.view')->name('users.index');
        Route::get('/users/{user}', [Admin\UserController::class, 'show'])->can('users.view')->name('users.show');
        Route::post('/users/{user}/role', [Admin\UserController::class, 'role'])->can('users.manage')->middleware('password.recent')->name('users.role');
        Route::post('/users/{user}/status', [Admin\UserController::class, 'status'])->can('users.manage')->middleware('password.recent')->name('users.status');
        Route::post('/users/{user}/balance', [Admin\UserController::class, 'adjustBalance'])->can('balances.adjust')->middleware('password.recent')->name('users.balance');
        Route::post('/users/{user}/sessions/revoke', [Admin\UserController::class, 'revokeSessions'])->can('sessions.revoke')->name('users.sessions.revoke');

        Route::get('/sellers', [Admin\SellerController::class, 'index'])->can('sellers.manage')->name('sellers.index');
        Route::post('/sellers/{profile}/approve', [Admin\SellerController::class, 'approve'])->can('sellers.manage')->name('sellers.approve');
        Route::post('/sellers/{profile}/reject', [Admin\SellerController::class, 'reject'])->can('sellers.manage')->name('sellers.reject');

        Route::get('/products', [Admin\ProductController::class, 'index'])->can('products.manage')->name('products.index');
        Route::get('/products/{product:id}', [Admin\ProductController::class, 'show'])->can('products.manage')->name('products.show');
        Route::get('/products/{product:id}/files/{file}', [Admin\ProductController::class, 'downloadFile'])->can('products.manage')->name('products.files.download');
        Route::post('/products/{product:id}/status', [Admin\ProductController::class, 'status'])->can('products.manage')->name('products.status');

        Route::get('/categories', [Admin\CategoryController::class, 'index'])->can('categories.manage')->name('categories.index');
        Route::post('/categories', [Admin\CategoryController::class, 'store'])->can('categories.manage')->name('categories.store');
        Route::put('/categories/{category:id}', [Admin\CategoryController::class, 'update'])->can('categories.manage')->name('categories.update');
        Route::delete('/categories/{category:id}', [Admin\CategoryController::class, 'destroy'])->can('categories.manage')->name('categories.destroy');

        Route::get('/orders', [Admin\OrderController::class, 'index'])->can('orders.view')->name('orders.index');
        Route::get('/orders/{order}', [Admin\OrderController::class, 'show'])->can('orders.view')->name('orders.show');
        Route::post('/orders/{order}/cancel', [Admin\OrderController::class, 'cancel'])->can('orders.manage')->name('orders.cancel');
        Route::post('/orders/{order}/items/{item}/deliver', [Admin\OrderController::class, 'deliverItem'])->can('orders.manage')->name('orders.items.deliver');
        Route::post('/orders/{order}/items/{item}/reset-downloads', [Admin\OrderController::class, 'resetDownloads'])->can('orders.manage')->name('orders.items.reset-downloads');
        Route::post('/orders/{order}/redeliver', [Admin\OrderController::class, 'retryDelivery'])->can('orders.resend')->name('orders.redeliver');
        Route::post('/orders/{order}/invoice', [Admin\OrderController::class, 'regenerateInvoice'])->can('orders.manage')->name('orders.invoice');
        Route::post('/orders/{order}/refunds', [Admin\OrderController::class, 'refund'])->can('orders.manage')->middleware('password.recent')->name('orders.refund');

        Route::get('/payments', [Admin\PaymentController::class, 'index'])->can('payments.view')->name('payments.index');
        Route::get('/webhooks', [Admin\WebhookEventController::class, 'index'])->can('webhooks.manage')->name('webhooks.index');
        Route::post('/webhooks/{event}/retry', [Admin\WebhookEventController::class, 'retry'])->can('webhooks.manage')->name('webhooks.retry');

        Route::get('/payouts', [Admin\PayoutController::class, 'index'])->can('payouts.manage')->name('payouts.index');
        Route::post('/payouts/{payout}/approve', [Admin\PayoutController::class, 'approve'])->can('payouts.manage')->middleware('password.recent')->name('payouts.approve');
        Route::post('/payouts/{payout}/send', [Admin\PayoutController::class, 'send'])->can('payouts.manage')->middleware('password.recent')->name('payouts.send');
        Route::post('/payouts/{payout}/paid', [Admin\PayoutController::class, 'paid'])->can('payouts.manage')->middleware('password.recent')->name('payouts.paid');
        Route::post('/payouts/{payout}/reject', [Admin\PayoutController::class, 'reject'])->can('payouts.manage')->middleware('password.recent')->name('payouts.reject');
        Route::get('/reconciliation', Admin\ReconciliationController::class)->can('payouts.manage')->name('reconciliation');

        Route::get('/coupons', [Admin\CouponController::class, 'index'])->can('coupons.manage')->name('coupons.index');
        Route::post('/coupons', [Admin\CouponController::class, 'store'])->can('coupons.manage')->name('coupons.store');
        Route::post('/coupons/{coupon}/toggle', [Admin\CouponController::class, 'toggle'])->can('coupons.manage')->name('coupons.toggle');
        Route::get('/gift-cards', [Admin\GiftCardController::class, 'index'])->can('giftcards.manage')->name('gift-cards.index');
        Route::post('/gift-cards', [Admin\GiftCardController::class, 'store'])->can('giftcards.manage')->middleware('password.recent')->name('gift-cards.store');
        Route::get('/exchange-rates', [Admin\ExchangeRateController::class, 'index'])->can('rates.manage')->name('rates.index');
        Route::post('/exchange-rates', [Admin\ExchangeRateController::class, 'store'])->can('rates.manage')->middleware('password.recent')->name('rates.store');

        Route::get('/tickets', [Admin\TicketController::class, 'index'])->can('tickets.manage')->name('tickets.index');
        Route::get('/reviews', [Admin\ReviewController::class, 'index'])->can('reviews.moderate')->name('reviews.index');
        Route::post('/reviews/{review}/status', [Admin\ReviewController::class, 'status'])->can('reviews.moderate')->name('reviews.status');
        Route::get('/audit', [Admin\AuditLogController::class, 'index'])->can('audit.view')->name('audit.index');

        Route::get('/reports', Admin\ReportController::class)->can('reports.view')->name('reports');
        Route::get('/exports', [Admin\ExportController::class, 'index'])->can('exports.download')->name('exports.index');
        Route::get('/exports/download', [Admin\ExportController::class, 'download'])->can('exports.download')->middleware('password.recent')->name('exports.download');
        Route::get('/settings', [Admin\SettingsController::class, 'index'])->can('settings.manage')->name('settings.index');
        Route::put('/settings', [Admin\SettingsController::class, 'update'])->can('settings.manage')->middleware('password.recent')->name('settings.update');
        Route::get('/announcements', [Admin\AnnouncementController::class, 'index'])->can('announcements.manage')->name('announcements.index');
        Route::post('/announcements', [Admin\AnnouncementController::class, 'store'])->can('announcements.manage')->name('announcements.store');
        Route::post('/announcements/{announcement}/toggle', [Admin\AnnouncementController::class, 'toggle'])->can('announcements.manage')->name('announcements.toggle');
        Route::delete('/announcements/{announcement}', [Admin\AnnouncementController::class, 'destroy'])->can('announcements.manage')->name('announcements.destroy');
    });
});
