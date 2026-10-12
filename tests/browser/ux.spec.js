// UX regression checks: feedback, synchronisation between roles, session end,
// confirmations and keyboard use (docs/ux). JavaScript on unless a test says otherwise.
const { test, expect } = require('@playwright/test');
const { login, firstProductPath } = require('./helpers');

test.use({ javaScriptEnabled: true });
test.describe.configure({ mode: 'serial' });

const nextPoll = (page) => page.waitForResponse((r) => r.url().includes('/live?'), { timeout: 20000 });
const unique = (prefix) => `${prefix} ${Date.now().toString(36)}`;

test('staff approval reaches the seller\'s product list without a reload', async ({ browser }) => {
    test.setTimeout(150000);
    const sellerContext = await browser.newContext({ javaScriptEnabled: true });
    const seller = await sellerContext.newPage();
    await login(seller, 'seller');
    const title = unique('Live review check');
    await seller.goto('/seller/products/new');
    await seller.getByLabel('Title').fill(title);
    await seller.getByLabel('Description').fill('A manually delivered test product.');
    await seller.getByLabel('Price').fill('9.00');
    await seller.getByLabel('Delivery').selectOption('manual');
    await seller.getByRole('button', { name: 'Save draft' }).click();
    await seller.getByRole('button', { name: 'Submit for review' }).click();
    await expect(seller.locator('.flash-success')).toBeVisible();

    await seller.goto('/seller/products');
    const row = seller.locator('#live-content tr', { hasText: title });
    await expect(row.locator('.status')).toHaveText('Pending review');
    await nextPoll(seller);
    await seller.evaluate(() => { window.__sameDocument = true; });

    const staffContext = await browser.newContext({ javaScriptEnabled: true });
    const staff = await staffContext.newPage();
    await login(staff, 'admin');
    await staff.goto('/admin/products');
    await staff.locator('#live-content a', { hasText: title }).click();
    await staff.getByRole('button', { name: 'Approve and list' }).click();
    await expect(staff.locator('.flash-success')).toBeVisible();

    await expect(row.locator('.status')).toHaveText('Active', { timeout: 25000 });
    expect(await seller.evaluate(() => window.__sameDocument)).toBe(true);
    await sellerContext.close();
    await staffContext.close();
});

test('when the session ends the page says so, keeps what was typed, and the form works after signing in again', async ({ browser }) => {
    test.setTimeout(120000);
    const context = await browser.newContext({ javaScriptEnabled: true });
    const page = await context.newPage();
    await login(page, 'buyer');
    await page.goto('/tickets/new');
    const subject = unique('Session check');
    await page.getByLabel('Subject').fill(subject);
    await page.getByLabel('Message').fill('Typed before the session ended.');
    await nextPoll(page);

    await context.clearCookies(); // the session ends (expiry, sign-out elsewhere, revocation)
    await expect(page.locator('.live-region')).toContainText('You are signed out', { timeout: 25000 });
    await expect(page.getByRole('link', { name: 'Sign in again' })).toHaveAttribute('target', '_blank');
    await expect(page.getByLabel('Message')).toHaveValue('Typed before the session ended.');

    const other = await context.newPage();
    await login(other, 'buyer');
    await other.close();
    await expect(page.locator('.live-region')).toContainText('You are signed in again', { timeout: 25000 });

    await page.getByRole('button', { name: 'Open ticket' }).click();
    await expect(page.locator('h1')).toContainText(subject);
    await context.close();
});

test('a buyer\'s new order shows up in the staff order list without a reload', async ({ browser }) => {
    test.setTimeout(150000);
    const staffContext = await browser.newContext({ javaScriptEnabled: true });
    const staff = await staffContext.newPage();
    await login(staff, 'admin');
    await staff.goto('/admin/orders');
    await nextPoll(staff);

    const buyerContext = await browser.newContext({ javaScriptEnabled: true });
    const buyer = await buyerContext.newPage();
    await login(buyer, 'buyer');
    await buyer.goto(await firstProductPath(buyer));
    await buyer.getByRole('button', { name: 'Add to cart' }).click();
    await buyer.getByRole('link', { name: 'Continue to checkout' }).click();
    await buyer.getByLabel('Shop balance').check();
    await buyer.getByLabel(/I accept the/).check();
    await buyer.getByRole('button', { name: /Place order and pay/ }).click();
    const shortId = (await buyer.locator('.flash').innerText()).match(/Order ([0-9a-f]{8}) paid\./)[1];

    await expect(staff.locator('#live-content')).toContainText(shortId, { timeout: 25000 });
    await buyerContext.close();
    await staffContext.close();
});

test('leaving a page with a half-written message asks first', async ({ page }) => {
    await login(page, 'buyer');
    await page.goto('/tickets/new');
    await page.getByLabel('Message').fill('Half of a long explanation');
    const dialog = new Promise((resolve) => page.once('dialog', (d) => { resolve(d.type()); d.dismiss(); }));
    await page.close({ runBeforeUnload: true });
    expect(await dialog).toBe('beforeunload');
});

// Moves the focus with Tab only, as a keyboard user would, until the check matches.
async function tabTo(page, check, limit = 60) {
    for (let i = 0; i < limit; i++) {
        await page.keyboard.press('Tab');
        if (await page.evaluate(check)) return;
    }
    throw new Error('not reachable with Tab: ' + check);
}

test('a purchase can be completed with the keyboard alone', async ({ page }) => {
    await page.goto('/login');
    await tabTo(page, () => document.activeElement.id === 'f-email');
    await page.keyboard.type('customer@example.test');
    await tabTo(page, () => document.activeElement.id === 'f-password');
    await page.keyboard.type('correct-horse-battery-1');
    await page.keyboard.press('Enter');
    await expect(page.locator('.flash-success')).toContainText('Signed in as');

    await page.goto(await firstProductPath(page));
    await tabTo(page, () => document.activeElement.textContent.trim() === 'Add to cart');
    await page.keyboard.press('Enter');
    await expect(page.locator('.flash-success')).toContainText('to your cart');
    await tabTo(page, () => document.activeElement.textContent.trim() === 'Continue to checkout');
    await page.keyboard.press('Enter');
    await page.waitForURL(/\/checkout$/);
    // One Tab stop per radio group; the arrow keys choose within it.
    await tabTo(page, () => document.activeElement.name === 'payment_method');
    for (let i = 0; i < 5 && await page.evaluate(() => document.activeElement.value) !== 'balance'; i++) {
        await page.keyboard.press('ArrowDown');
    }
    await expect(page.getByLabel('Shop balance')).toBeChecked();
    await tabTo(page, () => document.activeElement.name === 'accept_terms');
    await page.keyboard.press('Space');
    await tabTo(page, () => /Place order and pay/.test(document.activeElement.textContent));
    await page.keyboard.press('Enter');
    await expect(page.locator('.flash')).toContainText(/Order [0-9a-f]{8} paid\./);
});

test('a purchase can be completed on a phone', async ({ browser }) => {
    const context = await browser.newContext({ javaScriptEnabled: true, viewport: { width: 375, height: 812 }, isMobile: true, hasTouch: true });
    const page = await context.newPage();
    await login(page, 'customer');
    await page.goto('/products');
    await page.locator('article.product-card').first().tap({ position: { x: 20, y: 120 } }); // the whole card is the link
    await expect(page.getByRole('button', { name: 'Add to cart' })).toBeVisible();
    await page.getByRole('button', { name: 'Add to cart' }).tap();
    await page.getByRole('link', { name: 'Continue to checkout' }).tap();
    await page.getByLabel('Shop balance').tap();
    await page.getByLabel(/I accept the/).tap();
    await page.getByRole('button', { name: /Place order and pay/ }).tap();
    await expect(page.locator('.flash')).toContainText(/Order [0-9a-f]{8} paid\./);
    expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBe(0);
    await context.close();
});

test('search suggestions can be used with the keyboard', async ({ page }) => {
    await page.goto('/');
    const input = page.locator('#site-search');
    await input.fill('pho');
    const first = page.locator('#search-suggestions a').first();
    await expect(first).toBeVisible();
    await expect(page.locator('#search-suggestions-status')).toContainText(/suggestion/);
    await page.keyboard.press('ArrowDown');
    await expect(first).toBeFocused();
    await page.keyboard.press('ArrowUp');
    await expect(input).toBeFocused();
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('Escape');
    await expect(input).toBeFocused();
    await expect(page.locator('#search-suggestions')).toBeHidden();
});

test('a form sent twice in quick succession is sent once', async ({ page }) => {
    await login(page, 'buyer');
    await page.goto('/tickets/new');
    await page.getByLabel('Subject').fill(unique('Double submit'));
    await page.getByLabel('Message').fill('Only one ticket, please.');
    let posts = 0;
    await page.route('**/tickets', (route) => {
        if (route.request().method() !== 'POST') return route.continue();
        posts++;
        return route.fulfill({ status: 204 }); // stay on the page so a second submit can be tried
    });
    await page.evaluate(() => {
        const form = document.querySelector('#live-content form.stack');
        form.requestSubmit();
        setTimeout(() => form.requestSubmit(), 100);
    });
    await page.waitForTimeout(1500);
    expect(posts).toBe(1);
});

test('the error summary takes the focus after a failed submission', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Email').fill('buyer@example.test');
    await page.getByLabel('Password', { exact: true }).fill('wrong-password-123');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page.locator('.flash-error')).toBeVisible();
    await expect(page.locator('.flash-error')).toBeFocused();
});

test.describe('without JavaScript', () => {
    test.use({ javaScriptEnabled: false });

    test('an irreversible action asks first and can be called off', async ({ page }) => {
        test.setTimeout(90000);
        await login(page, 'admin');
        await page.goto('/admin/categories');
        const name = unique('Temporary category');
        await page.locator('#new-name').fill(name);
        await page.getByRole('button', { name: 'Add category' }).click();
        await expect(page.locator('.flash-success')).toBeVisible();

        const card = page.locator('#live-content .card', { hasText: name });
        await card.getByText(`Delete ${name}`).click(); // opens the confirmation, deletes nothing
        await expect(card.locator('.confirm-panel')).toContainText('cannot be undone');
        await page.reload();
        await expect(page.locator('#live-content .card', { hasText: name })).toHaveCount(1);

        await card.getByText(`Delete ${name}`).click();
        await card.getByRole('button', { name: 'Yes, delete category' }).click();
        await expect(page.locator('.flash-success')).toBeVisible();
        await expect(page.locator('#live-content .card', { hasText: name })).toHaveCount(0);
    });

    test('the colour scheme setting applies to every page and survives a reload', async ({ page }) => {
        await page.goto('/');
        await page.getByLabel('Colour scheme').selectOption('dark');
        await page.getByRole('button', { name: 'Apply', exact: true }).click();
        await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
        await page.goto('/products');
        await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
        expect(await page.evaluate(() => getComputedStyle(document.documentElement).backgroundColor)).toBe('rgb(18, 20, 23)');
        await page.getByLabel('Colour scheme').selectOption('system');
        await page.getByRole('button', { name: 'Apply', exact: true }).click();
        await expect(page.locator('html')).not.toHaveAttribute('data-theme', /.+/);
    });

    test('a deep link survives the sign-in', async ({ page }) => {
        await page.goto('/wallet');
        await expect(page).toHaveURL(/\/login$/);
        await page.getByLabel('Email').fill('buyer@example.test');
        await page.getByLabel('Password', { exact: true }).fill('correct-horse-battery-1');
        await page.getByRole('button', { name: 'Sign in' }).click();
        await expect(page).toHaveURL(/\/wallet$/);
    });
});
