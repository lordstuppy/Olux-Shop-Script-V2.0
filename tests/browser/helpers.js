const { expect } = require('@playwright/test');
const pages = require('./pages');

async function login(page, role) {
    await page.goto('/login');
    await page.getByLabel('Email').fill(pages.accounts[role]);
    await page.getByLabel('Password').fill(pages.password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page.locator('.flash-success')).toContainText('Signed in as');
}

async function firstProductPath(page) {
    await page.goto('/products');
    const href = await page.locator('article.card .card-title a').first().getAttribute('href');
    return new URL(href).pathname;
}

module.exports = { login, firstProductPath };
