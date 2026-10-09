// Every page must render and every core flow must work with JavaScript disabled.
const { test, expect } = require('@playwright/test');
const pages = require('./pages');
const { login, firstProductPath } = require('./helpers');

async function checkPage(page, path) {
    const errors = [];
    page.on('console', (msg) => msg.type() === 'error' && errors.push(msg.text()));
    const response = await page.goto(path);
    expect(response.status(), `${path} status`).toBe(200);
    await expect(page.locator('h1'), `${path} has one h1`).toHaveCount(1);
    await expect(page.locator('main#main')).toBeVisible();
    // No CSP violations or other console errors.
    expect(errors, `${path} console errors`).toEqual([]);
}

test.describe('pages render without JavaScript', () => {
    test('guest pages', async ({ page }) => {
        const product = await firstProductPath(page);
        for (const path of pages.guest) {
            await checkPage(page, path === ':product' ? product : path);
        }
    });

    for (const role of ['buyer', 'seller', 'admin']) {
        test(`${role} pages`, async ({ page }) => {
            await login(page, role);
            for (const path of pages[role]) {
                await checkPage(page, path);
            }
        });
    }
});

test('buy with balance without JavaScript', async ({ page }) => {
    await login(page, 'buyer');
    await page.goto(await firstProductPath(page));
    await page.getByRole('button', { name: 'Add to cart' }).click();
    await expect(page.locator('.flash-success')).toContainText('to your cart');

    await page.getByRole('link', { name: 'Continue to checkout' }).click();
    await page.getByLabel('Shop balance').check();
    await page.getByLabel(/I accept the/).check();
    await page.getByRole('button', { name: /Place order and pay/ }).click();

    await expect(page.locator('.flash')).toContainText(/Order [0-9a-f]{8} paid\./);
    await page.getByRole('link', { name: 'View order details' }).click();
    await expect(page.getByRole('link', { name: /Download/ }).first()).toBeVisible();
});

test('buy with crypto via the Shkeeper mock without JavaScript', async ({ page, request }) => {
    test.skip(!process.env.SHKEEPER_MOCK_URL, 'SHKEEPER_MOCK_URL not set');
    await login(page, 'buyer');
    await page.goto(await firstProductPath(page));
    await page.getByRole('button', { name: 'Add to cart' }).click();
    await page.getByRole('link', { name: 'Continue to checkout' }).click();
    await page.getByLabel('Cryptocurrency (self-hosted Shkeeper)').check();
    await page.getByLabel(/I accept the/).check();
    await page.getByRole('button', { name: /Place order and pay/ }).click();

    await expect(page.locator('h1')).toContainText('Pay order');
    await expect(page.locator('img.qr')).toBeVisible();
    const orderId = page.url().match(/orders\/([0-9a-f-]{36})\/pay/)[1];

    // The buyer's own page cannot mark the order paid.
    await page.getByRole('link', { name: /check status/ }).click();
    await expect(page.locator('.flash')).toContainText('waiting for payment confirmation');

    // Shkeeper (mock) confirms the payment through the signed webhook.
    const paid = await request.post(`${process.env.SHKEEPER_MOCK_URL}/__mock/pay/${orderId}`, { data: {} });
    expect((await paid.json()).callback_http_status).toBe(202);

    await page.reload();
    await expect(page.locator('.flash')).toContainText(/Order [0-9a-f]{8} paid\./);
});

test('login, cart update and logout without JavaScript', async ({ page }) => {
    await login(page, 'buyer');
    await page.goto(await firstProductPath(page));
    await page.getByRole('button', { name: 'Add to cart' }).click();
    const qty = page.getByLabel(/Quantity of/).first();
    await qty.fill('2');
    await page.getByRole('button', { name: 'Update' }).first().click();
    await expect(page.locator('.flash-success')).toContainText('to quantity 2');
    await page.getByRole('button', { name: /Remove/ }).first().click();
    await expect(page.locator('.flash-success')).toContainText('Removed');
    await page.getByRole('button', { name: 'Sign out' }).click();
    await expect(page.locator('.flash-success')).toContainText('signed out');
});
