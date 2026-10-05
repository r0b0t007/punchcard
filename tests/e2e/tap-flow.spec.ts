import { execFileSync } from 'node:child_process';
import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * The customer tap flow (CHW-25) on an iPhone: C1 signed out, sign up, C2,
 * a second tap inside the cooldown, then Arabic right to left and dark mode.
 *
 * Needs the demo data (php artisan db:seed) and a SUN master key
 * (NFC_SUN_MASTER_KEY): punchcard:fake-tap writes a valid tap URL for the
 * demo's first stamper, as the stamper would.
 */
test.describe.configure({ mode: 'serial' });

// mobile-safari is the only WebKit project; one project, since every tap spends the stamper's next counter.
test.skip(
    ({ browserName }) => browserName !== 'webkit',
    'Runs on mobile-safari only.',
);

/** A fresh tap URL (path and query) from the demo's first stamper. */
function freshTap(): string {
    const output = execFileSync('php', ['artisan', 'punchcard:fake-tap', '1'], {
        encoding: 'utf8',
    });
    const url = new URL(output.trim().split(/\s+/).pop() ?? '');

    return `${url.pathname}${url.search}`;
}

async function signUp(page: Page): Promise<void> {
    await page.getByRole('link', { name: 'Continue with email' }).click();
    await page.locator('#name').fill('Salma Tapper');
    await page.locator('#email').fill(`tap-${Date.now()}@example.com`);
    await page.locator('#password').fill('a-long-test-password-1');
    await page.locator('#password_confirmation').fill('a-long-test-password-1');
    await page.locator('[data-test="register-user-button"]').click();
}

test.describe('in English, light', () => {
    test.use({ locale: 'en-US', colorScheme: 'light' });

    test('a first tap waits for sign-up, stamps once, then cools down', async ({
        page,
    }) => {
        await page.goto(freshTap());
        await expect(
            page.getByRole('heading', { name: 'Your first stamp is waiting' }),
        ).toBeVisible();
        await expect(page.getByText('Sign in to keep it')).toBeVisible();

        await signUp(page);

        await expect(page).toHaveURL(/\/t\/result$/);
        await expect(page.getByText(/^Stamped at \d{2}:\d{2}$/)).toBeVisible();
        await expect(
            page.getByRole('heading', { name: '1 of 10' }),
        ).toBeVisible();

        await page.reload();
        await expect(
            page.getByRole('heading', { name: '1 of 10' }),
        ).toBeVisible();

        await page.goto(freshTap());
        await expect(
            page.getByRole('heading', { name: /^Already stamped/ }),
        ).toBeVisible();
        await expect(page.getByText('Next stamp available at')).toBeVisible();
    });
});

test.describe('in Arabic', () => {
    test.use({ locale: 'ar-MA' });

    test('shows the first tap right to left', async ({ page }) => {
        await page.goto(freshTap());

        await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
        await expect(
            page.getByRole('heading', { name: 'طابعك الأول في انتظارك' }),
        ).toBeVisible();
    });
});

test.describe('in dark mode', () => {
    test.use({ locale: 'en-US', colorScheme: 'dark' });

    test('shows the first tap on the dark theme', async ({
        page,
    }, testInfo) => {
        await page.goto(freshTap());

        await expect(page.locator('html')).toHaveClass(/dark/);
        await expect(
            page.getByRole('heading', { name: 'Your first stamp is waiting' }),
        ).toBeVisible();
        await testInfo.attach('first-tap-dark', {
            body: await page.screenshot({ fullPage: true }),
            contentType: 'image/png',
        });
    });
});
