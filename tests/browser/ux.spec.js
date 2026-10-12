// UX regression checks: feedback, synchronisation between roles, session end,
// confirmations and keyboard use (docs/ux). JavaScript on unless a test says otherwise.
const { test, expect } = require('@playwright/test');
const { login } = require('./helpers');

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
