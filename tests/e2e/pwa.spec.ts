import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * The installable apps (CHW-35): on a phone, a café's join page and an
 * owner's dashboard each register the service worker for the whole site,
 * come under its control, and are installable: Chrome finds no problem
 * with the page's manifest, icons or service worker. Lighthouse 12 dropped
 * its PWA audits, so this asks Chrome itself (CDP), on mobile-chrome only.
 *
 * Needs the demo data (php artisan db:seed) and a production build: the
 * service worker is built with the assets (public/sw.js).
 */
test.skip(
    ({ browserName, isMobile }) => browserName !== 'chromium' || !isMobile,
    'Installability comes from Chrome (CDP), on a phone.',
);

// Installing the worker downloads the app shell first: slow on a single-threaded dev server.
test.describe.configure({ timeout: 60_000 });

async function signIn(page: Page, email: string): Promise<void> {
    await page.goto('/login');
    await page.locator('#email').fill(email);
    await page.locator('#password').fill('password');
    await page.locator('[data-test="login-button"]').click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
}

/**
 * Waits for the service worker the page registered, then for it to control
 * the page: a worker installed on this visit controls the next load.
 * Returns its scope.
 */
async function serviceWorkerScope(page: Page): Promise<string> {
    const scope = await page.evaluate(
        async () => (await navigator.serviceWorker.ready).scope,
    );
    await page.reload();
    await expect
        .poll(() =>
            page.evaluate(() => navigator.serviceWorker.controller !== null),
        )
        .toBe(true);

    return scope;
}

async function installabilityErrors(page: Page): Promise<string[]> {
    const session = await page.context().newCDPSession(page);
    const { installabilityErrors } = await session.send(
        'Page.getInstallabilityErrors',
    );

    return installabilityErrors.map((error) => error.errorId);
}

// The join page, public: the demo customer is left to the other specs, as
// Fortify allows five sign-ins a minute per account (My cards is covered by
// InstallableAppsTest).
test("a café's join page is the installable punchcard app", async ({
    page,
}) => {
    await page.goto('/j/cafe-hafa');

    await expect(page.locator('link[rel="manifest"]')).toHaveAttribute(
        'href',
        '/manifest-customer.webmanifest',
    );
    expect(new URL(await serviceWorkerScope(page)).pathname).toBe('/');
    expect(await installabilityErrors(page)).toEqual([]);
});

test("an owner's dashboard is the installable punchcard Pro app", async ({
    page,
}) => {
    await signIn(page, 'owner@cafe.demo.test');
    await expect(page).toHaveURL(/\/dashboard$/);

    await expect(page.locator('link[rel="manifest"]')).toHaveAttribute(
        'href',
        '/manifest-business.webmanifest',
    );
    expect(new URL(await serviceWorkerScope(page)).pathname).toBe('/');
    expect(await installabilityErrors(page)).toEqual([]);
});

test('the marketing site is no app and registers no service worker', async ({
    page,
}) => {
    await page.goto('/');

    await expect(page.locator('link[rel="manifest"]')).toHaveCount(0);
    expect(
        await page.evaluate(
            async () =>
                (await navigator.serviceWorker.getRegistrations()).length,
        ),
    ).toBe(0);
});
