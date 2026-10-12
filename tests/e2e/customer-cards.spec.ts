import path from 'node:path';
import { test as base, expect } from '@playwright/test';
import type { Page, TestInfo } from '@playwright/test';

/**
 * The customer's cards (CHW-27) on a phone: My cards (C5), where a customer
 * lands after signing in, then one card in full (C6), in English light and
 * dark and in Arabic right to left. Screenshots are attached to the report,
 * which CI keeps on every run.
 *
 * Needs the demo data (php artisan db:seed): the demo customer holds a card
 * of Café Hafa and one of Atlas Coffee, and has no saved language, so each
 * test's browser language decides. Nothing here taps or changes data, so it
 * runs on both phone projects; the desktop one is the business side's.
 */

/**
 * The demo customer signed in once per worker (Playwright's "authenticate
 * once" pattern), afresh on every run and per project, so each browser
 * checks where a customer lands: Fortify allows 5 sign-ins a minute per
 * email, which the tap flow test shares.
 */
const test = base.extend<object, { customerSession: string }>({
    storageState: ({ customerSession }, use) => use(customerSession),
    customerSession: [
        async ({ browser }, use, workerInfo) => {
            const file = path.join(
                workerInfo.project.outputDir,
                `.auth/customer-${workerInfo.project.name}-${workerInfo.parallelIndex}.json`,
            );
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

            await use(file);
        },
        { scope: 'worker' },
    ],
});

// In order, in one worker (one sign-in per project), every test run even after a failure.
test.describe.configure({ mode: 'default' });

test.skip(
    ({ isMobile }) => !isMobile,
    'The customer screens are phone screens.',
);

/** The snapshot My cards keeps in IndexedDB for offline use (offline-cards.ts), if any. */
function offlineSnapshot(page: Page): Promise<unknown> {
    return page.evaluate(
        () =>
            new Promise((resolve) => {
                const open = indexedDB.open('punchcard-offline');
                open.onerror = () => resolve(undefined);
                open.onsuccess = () => {
                    const database = open.result;

                    if (!database.objectStoreNames.contains('snapshots')) {
                        database.close();
                        resolve(undefined);

                        return;
                    }

                    const get = database
                        .transaction('snapshots')
                        .objectStore('snapshots')
                        .get('my-cards');
                    get.onsuccess = () => {
                        database.close();
                        resolve(get.result);
                    };
                };
            }),
    );
}

async function attach(page: Page, testInfo: TestInfo, name: string) {
    await testInfo.attach(name, {
        body: await page.screenshot({ fullPage: true }),
        contentType: 'image/png',
    });
}

/** The page is in the browser's language: the demo customer has no saved one to win over it. */
async function expectLanguage(page: Page, lang: string): Promise<void> {
    await expect(
        page.locator('html'),
        "A saved language wins over the browser's: clear users.locale for customer@demo.test.",
    ).toHaveAttribute('lang', lang);
}

/**
 * Every visible element sits inside the phone's width, on both sides: a
 * right-to-left page clips what runs past its right edge instead of
 * scrolling to it, so the scroll width alone would miss it.
 */
async function expectNothingOffTheSides(page: Page): Promise<void> {
    const outside = await page.evaluate(() => {
        const width = document.documentElement.clientWidth;

        return [...document.body.querySelectorAll('*')]
            .filter((element) => {
                const box = element.getBoundingClientRect();

                return (
                    // Inertia's page-load bar slides in from off screen on purpose.
                    element.closest('#nprogress') === null &&
                    box.width > 1 &&
                    box.height > 1 &&
                    getComputedStyle(element).visibility !== 'hidden' &&
                    (box.left < -1 || box.right > width + 1)
                );
            })
            .map((element) => element.outerHTML.slice(0, 120));
    });

    expect(outside, 'elements outside the screen').toEqual([]);
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

        await expectLanguage(page, 'en');
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
        await expectNothingOffTheSides(page);
        await attach(page, testInfo, 'my-cards-light');

        // Kept on the phone for when it is offline (CHW-35): the cards shown, in the language shown.
        await expect
            .poll(() => offlineSnapshot(page))
            .toMatchObject({
                firstName: 'Salma',
                locale: 'en',
                cards: [{}, {}],
            });
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
        await expectNothingOffTheSides(page);
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
        // The class is set before React renders: wait for the page itself.
        await expect(
            page.getByRole('heading', { level: 1, name: /^Hello/ }),
        ).toBeVisible();
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
        await expectLanguage(page, 'ar');
        await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
        await expect(
            page.getByRole('heading', { level: 1, name: /^مرحبًا/ }),
        ).toBeVisible();
        await expectNothingOffTheSides(page);
        await attach(page, testInfo, 'my-cards-arabic');

        await openCafeHafa(page);
        await expect(
            page.getByRole('heading', { name: 'طوابعك' }),
        ).toBeVisible();
        await expectNothingOffTheSides(page);
        await attach(page, testInfo, 'card-arabic');
    });
});
