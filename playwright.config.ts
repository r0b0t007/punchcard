import { defineConfig, devices } from '@playwright/test';

const baseURL = process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8000';

/**
 * E2E tests for the customer PWA, business portal and marketing site.
 * WebKit stands in for iOS Safari, where most café customers will tap.
 */
export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 1 : 0,
    reporter: process.env.CI
        ? [['github'], ['html', { open: 'never' }]]
        : 'list',
    use: {
        baseURL,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [
        { name: 'desktop-chromium', use: { ...devices['Desktop Chrome'] } },
        { name: 'mobile-safari', use: { ...devices['iPhone 15'] } },
        { name: 'mobile-chrome', use: { ...devices['Pixel 7'] } },
    ],
    webServer: process.env.E2E_BASE_URL
        ? undefined
        : {
              command: 'php artisan serve --host=127.0.0.1 --port=8000',
              url: baseURL,
              reuseExistingServer: !process.env.CI,
              timeout: 120_000,
          },
});
