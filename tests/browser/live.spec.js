// Live updates with JavaScript on (the rest of the suite runs without it).
const { test, expect } = require('@playwright/test');
const { login, firstProductPath } = require('./helpers');

test.use({ javaScriptEnabled: true });
test.describe.configure({ mode: 'serial' });

// Counts notification tones instead of playing them.
const fakeAudio = () => {
    window.__tones = 0;
    class FakeAudioContext {
        constructor() { this.state = 'running'; this.currentTime = 0; this.destination = {}; }
        resume() { this.state = 'running'; return Promise.resolve(); }
        createOscillator() {
            window.__tones++;
            return { frequency: { value: 0 }, connect() { return { connect() {} }; }, start() {}, stop() {} };
        }
        createGain() {
            return { gain: { setValueAtTime() {}, exponentialRampToValueAtTime() {} }, connect() { return {}; } };
        }
    }
    window.AudioContext = FakeAudioContext;
};

async function cryptoOrder(page) {
    await page.goto(await firstProductPath(page));
    await page.getByRole('button', { name: 'Add to cart' }).click();
    await page.getByRole('link', { name: 'Continue to checkout' }).click();
    await page.getByLabel('Cryptocurrency (self-hosted Shkeeper)').check();
    await page.getByLabel(/I accept the/).check();
    await page.getByRole('button', { name: /Place order and pay/ }).click();
    await expect(page.locator('h1')).toContainText('Pay order');
    return page.url().match(/orders\/([0-9a-f-]{36})\/pay/)[1];
}

const nextPoll = (page) => page.waitForResponse((r) => r.url().includes('/live?'), { timeout: 20000 });

test('the order page updates itself when the payment arrives, with a toast, a sound and a new badge count', async ({ page, request }) => {
    test.skip(!process.env.SHKEEPER_MOCK_URL, 'SHKEEPER_MOCK_URL not set');
    test.setTimeout(90000);
    await page.addInitScript(fakeAudio);
    await login(page, 'buyer');
    const orderId = await cryptoOrder(page);
    await page.goto(`/orders/${orderId}`);
    const status = page.locator('#live-content .status').first();
    await expect(status).toHaveText('Awaiting payment');
    await page.mouse.click(5, 5); // browsers allow sound only after an interaction
    await nextPoll(page); // the first poll records the newest notification already shown
    await page.evaluate(() => { window.__sameDocument = true; });

    const paid = await request.post(`${process.env.SHKEEPER_MOCK_URL}/__mock/pay/${orderId}`, { data: {} });
    expect((await paid.json()).callback_http_status).toBe(202);

    // The toast and the page change come from the same poll (at most 10 s later).
    await expect(page.locator('.live-region')).toContainText('New notification: Order', { timeout: 25000 });
    await expect(status).toHaveText(/Paid|Delivered/);
    expect(await page.evaluate(() => window.__sameDocument)).toBe(true); // updated in place, not reloaded
    await expect(page.locator('[data-live-unread]')).toBeVisible();
    expect(await page.evaluate(() => window.__tones)).toBeGreaterThan(0);
});

test('a filled-in form is never replaced; the update is offered instead', async ({ browser }) => {
    test.setTimeout(150000);
    const buyerContext = await browser.newContext({ javaScriptEnabled: true });
    const buyer = await buyerContext.newPage();
    await login(buyer, 'buyer');
    await buyer.goto('/tickets/new');
    await buyer.getByLabel('Subject').fill('Live update check');
    await buyer.getByLabel('Message').fill('Is my key ready?');
    await buyer.getByRole('button', { name: 'Open ticket' }).click();
    const ticketPath = new URL(buyer.url()).pathname;
    await buyer.getByLabel('Your reply').fill('A draft I am still writing');
    await nextPoll(buyer);

    const staffContext = await browser.newContext({ javaScriptEnabled: true });
    const staff = await staffContext.newPage();
    await login(staff, 'admin');
    await staff.goto(ticketPath);
    await staff.getByLabel('Your reply').fill('Yes, it is ready now.');
    await staff.getByRole('button', { name: 'Send reply' }).click();

    await expect(buyer.locator('.live-region')).toContainText('This page has new information.', { timeout: 25000 });
    await expect(buyer.getByLabel('Your reply')).toHaveValue('A draft I am still writing');
    await expect(buyer.locator('#live-content')).not.toContainText('Yes, it is ready now.');
    await buyer.getByRole('button', { name: 'Show it' }).click();
    await expect(buyer.locator('#live-content')).toContainText('Yes, it is ready now.');
    await buyerContext.close();
    await staffContext.close();
});

test('the unread badge follows in every open tab, and the sound can be switched off', async ({ browser }) => {
    test.setTimeout(90000);
    const context = await browser.newContext({ javaScriptEnabled: true });
    await context.addInitScript(fakeAudio);
    const first = await context.newPage();
    await login(first, 'buyer');
    const second = await context.newPage();
    await second.goto('/orders');
    await expect(second.locator('[data-live-unread]')).toBeVisible();

    await first.goto('/notifications');
    await first.getByRole('button', { name: 'Mark all as read' }).click();
    await expect(second.locator('[data-live-unread]')).toBeHidden({ timeout: 25000 });

    await first.goto('/account');
    await first.getByLabel('Play a short sound when a new notification arrives').uncheck();
    await first.getByRole('button', { name: 'Save notification settings' }).click();
    await expect(first.locator('body')).toHaveAttribute('data-live-sound', '0');
    await context.close();
});

test('a lost connection is shown and clears when the server answers again', async ({ page }) => {
    test.setTimeout(150000);
    await login(page, 'buyer');
    await page.goto('/orders');
    await page.route('**/live?**', (route) => route.abort());
    await expect(page.locator('.live-region')).toContainText('Connection lost. Trying again...', { timeout: 60000 });
    await page.unroute('**/live?**');
    await expect(page.locator('.live-region')).toContainText('Connection restored.', { timeout: 70000 });
});
