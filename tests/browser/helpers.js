const crypto = require('crypto');
const { expect } = require('@playwright/test');
const pages = require('./pages');

// Development-only TOTP secret of the seeded admin (DatabaseSeeder::ADMIN_TOTP_SECRET).
const ADMIN_TOTP_SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

function base32Decode(input) {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const ch of input.replace(/=+$/, '')) {
        bits += alphabet.indexOf(ch).toString(2).padStart(5, '0');
    }
    const bytes = [];
    for (let i = 0; i + 8 <= bits.length; i += 8) {
        bytes.push(parseInt(bits.slice(i, i + 8), 2));
    }
    return Buffer.from(bytes);
}

// RFC 6238 TOTP, 30-second steps, 6 digits, HMAC-SHA1.
function totp(secret, step = Math.floor(Date.now() / 1000 / 30)) {
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(step));
    const hmac = crypto.createHmac('sha1', base32Decode(secret)).update(counter).digest();
    const offset = hmac[hmac.length - 1] & 0xf;
    const code = (hmac.readUInt32BE(offset) & 0x7fffffff) % 1000000;
    return String(code).padStart(6, '0');
}

// Codes are single-use per time step, so each admin login waits for a fresh step.
let lastAdminStep = 0;

async function login(page, role) {
    await page.goto('/login');
    await page.getByLabel('Email').fill(pages.accounts[role]);
    await page.getByLabel('Password').fill(pages.password);
    await page.getByRole('button', { name: 'Sign in' }).click();

    if (role === 'admin') {
        await expect(page.locator('h1')).toHaveText('Two-factor authentication');
        let step = Math.floor(Date.now() / 1000 / 30);
        if (step <= lastAdminStep) {
            await page.waitForTimeout(((lastAdminStep + 1) * 30 - Date.now() / 1000) * 1000 + 500);
            step = Math.floor(Date.now() / 1000 / 30);
        }
        lastAdminStep = step;
        await page.getByLabel('Authentication or recovery code').fill(totp(ADMIN_TOTP_SECRET, step));
        await page.getByRole('button', { name: 'Verify and sign in' }).click();
    }

    await expect(page.locator('.flash-success')).toContainText('Signed in as');
}

async function firstProductPath(page) {
    await page.goto('/products');
    const href = await page.locator('article.card .card-title a').first().getAttribute('href');
    return new URL(href).pathname;
}

module.exports = { login, firstProductPath, totp };
