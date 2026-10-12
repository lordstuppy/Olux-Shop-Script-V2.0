// Browser checks run against an already running shop (see run.sh).
const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
    testDir: '.',
    timeout: 60000,
    retries: 0,
    workers: 1,
    reporter: [['list']],
    use: {
        baseURL: process.env.SHOP_URL || 'http://127.0.0.1:8000',
        javaScriptEnabled: false,
        // Evidence for failed checks (uploaded by CI).
        screenshot: 'only-on-failure',
        trace: 'retain-on-failure',
    },
    projects: [
        { name: 'light' },
        // The accessibility scan (colour contrast included) again with the dark scheme.
        { name: 'dark', testMatch: /a11y\.spec\.js/, use: { colorScheme: 'dark' } },
        // And on a phone: wide tables scroll inside their box there (keyboard access), touch target sizes.
        { name: 'mobile', testMatch: /a11y\.spec\.js/, use: { viewport: { width: 375, height: 812 }, isMobile: true, hasTouch: true } },
    ],
});
