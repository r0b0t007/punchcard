import fs from 'node:fs';
import path from 'node:path';
import { test as base, expect } from '@playwright/test';
import type { Page, TestInfo } from '@playwright/test';

/**
 * The customer's cards (CHW-27) on a phone: My cards (C5), where a customer
 * lands after signing in, then one card in full (C6), in English light and
 * dark and in Arabic right to left. Screenshots are attached to the report.
 *
 * Needs the demo data (php artisan db:seed): the demo customer holds a card
 * of Café Hafa and one of Atlas Coffee. Nothing here taps or changes data,
 * so it runs on both phone projects; the desktop one is the business side's.
 */

/**
 * The demo customer signed in once per worker (Playwright's "authenticate
 * once" pattern): Fortify allows 5 sign-ins a minute per email, which the
 * tap flow test shares. Signing in also checks where a customer lands.
 */
const test = base.extend<object, { customerSession: string }>({
    storageState: ({ customerSession }, use) => use(customerSession),
    customerSession: [
        async ({ browser }, use, workerInfo) => {
            const file = path.join(
                workerInfo.project.outputDir,
                `.auth/customer-${workerInfo.parallelIndex}.json`,
            );

            if (!fs.existsSync(file)) {
                // A page made from the browser has none of the project's options: give it the base URL.
                const page = await browser.newPage({
                    baseURL: workerInfo.project.use.baseURL,
                    storageState: { cookies: [], origins: [] },
                });
                await page.goto('/login');
                await page.locator('#email').fill('customer@demo.test');
                await page.locator('#password').fill('password');
                await page.locator('[data-test="login-button"]').click();
                // A customer's home after signing in is My cards.
                await expect(page).toHaveURL(/\/app$/);
                await page.context().storageState({ path: file });
                await page.close();
            }

            await use(file);
        },
        { scope: 'worker' },
    ],
});

// One worker per project, so one sign-in per project.
test.describe.configure({ mode: 'serial' });

test.skip(
    ({ isMobile }) => !isMobile,
    'The customer screens are phone screens.',
);

async function attach(page: Page, testInfo: TestInfo, name: string) {
    await testInfo.attach(name, {
        body: await page.screenshot({ fullPage: true }),
        contentType: 'image/png',
    });
}

/** Opens the Café Hafa card from My cards: its page in full (C6). */
async function openCafeHafa(page: Page): Promise<void> {
    await page.goto('/app');
    await page.getByRole('link', { name: /Café Hafa/ }).click();
    await expect(page).toHaveURL(/\/app\/cards\/\d+$/);
}

test.describe('in English, light', () => {
    test.use({ locale: 'en-US', colorScheme: 'light' });

    test("lists the customer's cards with the tab bar", async ({
        page,
    }, testInfo) => {
        await page.goto('/app');

        await expect(
            page.getByRole('heading', { level: 1, name: /^Hello/ }),
        ).toBeVisible();
        await expect(
            page.getByRole('link', { name: /Café Hafa/ }),
        ).toBeVisible();
        await expect(
            page.getByRole('link', { name: /Atlas Coffee/ }),
        ).toBeVisible();
        await expect(
            page
                .getByRole('navigation', { name: 'Main' })
                .getByRole('link', { name: 'Cards' }),
        ).toHaveAttribute('aria-current', 'page');
        await attach(page, testInfo, 'my-cards-light');
    });

    test('shows a card in full', async ({ page }, testInfo) => {
        await openCafeHafa(page);

        await expect(
            page.getByRole('heading', { name: 'Your stamps' }),
        ).toBeVisible();
        await expect(
            page.getByRole('heading', { name: 'Where to use it' }),
        ).toBeVisible();
        await expect(
            page.getByRole('link', { name: 'Open in Maps' }).first(),
        ).toBeVisible();
        await expect(
            page.getByRole('checkbox', { name: 'Follow for offers' }),
        ).toBeVisible();
        await attach(page, testInfo, 'card-light');
    });
});

test.describe('in dark mode', () => {
    test.use({ locale: 'en-US', colorScheme: 'dark' });

    test('shows My cards and a card on the dark theme', async ({
        page,
    }, testInfo) => {
        await page.goto('/app');
        await expect(page.locator('html')).toHaveClass(/dark/);
        await attach(page, testInfo, 'my-cards-dark');

        await openCafeHafa(page);
        await expect(
            page.getByRole('heading', { name: 'Your stamps' }),
        ).toBeVisible();
        await attach(page, testInfo, 'card-dark');
    });
});

test.describe('in Arabic', () => {
    test.use({ locale: 'ar-MA' });

    test('shows My cards and a card right to left', async ({
        page,
    }, testInfo) => {
        await page.goto('/app');
        await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
        await expect(
            page.getByRole('heading', { level: 1, name: /^مرحبًا/ }),
        ).toBeVisible();
        await attach(page, testInfo, 'my-cards-arabic');

        await page.getByRole('link', { name: /Café Hafa/ }).click();
        await expect(page).toHaveURL(/\/app\/cards\/\d+$/);
        await expect(
            page.getByRole('heading', { name: 'طوابعك' }),
        ).toBeVisible();
        // Nothing runs off the side of the phone.
        expect(
            await page.evaluate(
                () =>
                    document.documentElement.scrollWidth <=
                    window.innerWidth + 1,
            ),
        ).toBe(true);
        await attach(page, testInfo, 'card-arabic');
    });
});
