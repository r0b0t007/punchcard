import { expect, test } from '@playwright/test';
import type { CDPSession, Page } from '@playwright/test';

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

const sessions = new WeakMap<Page, CDPSession>();

/** The page's Chrome DevTools Protocol session, one per page. */
async function cdp(page: Page): Promise<CDPSession> {
    let session = sessions.get(page);

    if (!session) {
        session = await page.context().newCDPSession(page);
        sessions.set(page, session);
    }

    return session;
}

function offlineDatabases(page: Page): Promise<string[]> {
    return page.evaluate(async () =>
        (await indexedDB.databases()).map((database) => database.name ?? ''),
    );
}

async function installabilityErrors(page: Page): Promise<string[]> {
    const session = await cdp(page);
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

test('a tap never waits for the service worker', async ({ page }) => {
    await page.goto('/j/cafe-hafa');
    await serviceWorkerScope(page);

    // Chrome tells which static route a navigation took (InstallEvent.addRoutes),
    // on the page it ends at: the tap's redirects follow on the same request.
    const session = await cdp(page);
    await session.send('Network.enable');
    const routing: unknown[] = [];
    session.on('Network.responseReceived', ({ type, response }) => {
        if (type === 'Document') {
            routing.push(response.serviceWorkerRouterInfo ?? null);
        }
    });
    await page.goto('/t?e=00&c=00');

    await expect.poll(() => routing.length).toBeGreaterThan(0);
    expect(routing[0]).toMatchObject({ matchedSourceType: 'network' });
});

test("an owner's dashboard is the installable punchcard Pro app", async ({
    page,
}) => {
    await page.addInitScript(() => {
        const register = navigator.serviceWorker.register.bind(
            navigator.serviceWorker,
        );
        Object.assign(window, { registrations: 0 });
        navigator.serviceWorker.register = (...args) => {
            Object.assign(window, {
                registrations:
                    (window as unknown as { registrations: number })
                        .registrations + 1,
            });

            return register(...args);
        };
    });
    const registrations = () =>
        page.evaluate(
            () =>
                (window as unknown as { registrations: number }).registrations,
        );
    const manifest = page.locator('link[rel="manifest"]');
    const themeColor = page.locator('meta[name="theme-color"]');

    await signIn(page, 'owner@cafe.demo.test');
    await expect(page).toHaveURL(/\/business$/);
    await expect(manifest).toHaveAttribute(
        'href',
        '/manifest-business.webmanifest',
    );
    await expect(themeColor).toHaveCount(1);
    await expect.poll(registrations).toBe(1);
    // Installed (the app shell downloaded) before the visits, which it would slow on a one-process server.
    await page.evaluate(async () => {
        await navigator.serviceWorker.ready;
    });

    // Settings is no app's page: its tags go, and coming back registers nothing more.
    await page.getByRole('button', { name: 'Toggle sidebar' }).click();
    await page.getByRole('button', { name: /Yasmine Alaoui/ }).click();
    await page.getByRole('menuitem', { name: 'Settings' }).click();
    await expect(page).toHaveURL(/\/settings\//);
    await expect(manifest).toHaveCount(0);
    await expect(themeColor).toHaveCount(0);

    await page.goBack();
    await expect(manifest).toHaveCount(1);
    await page.goForward();
    await expect(manifest).toHaveCount(0);
    await page.goBack();
    await expect(page).toHaveURL(/\/business$/);
    await expect(manifest).toHaveCount(1);
    await expect(themeColor).toHaveCount(1);
    expect(await registrations()).toBe(1);

    expect(new URL(await serviceWorkerScope(page)).pathname).toBe('/');
    expect(await installabilityErrors(page)).toEqual([]);

    // punchcard Pro's scope as Chrome reads it (CHW-151): /business, so neither a tap (/t) nor a join link (/j) is
    // in it (InstallableAppsTest checks those paths against the manifest).
    const { manifest: parsed } = await (
        await cdp(page)
    ).send('Page.getAppManifest');
    expect(parsed?.scope).toBeDefined();
    expect(new URL(parsed?.scope ?? '', page.url()).pathname).toBe('/business');
});

test('a sign-out leaves no cards on the phone', async ({ page }) => {
    await signIn(page, 'owner@cafe.demo.test');
    await page.goto('/app');
    await serviceWorkerScope(page);
    // The service worker's copy of the cards (network first), written once the answer is back.
    await page.evaluate(() =>
        fetch('/api/me/cards', { headers: { Accept: 'application/json' } }),
    );
    await expect
        .poll(() =>
            page.evaluate(
                async () =>
                    (await (await caches.open('me-cards')).keys()).length,
            ),
        )
        .toBe(1);
    await expect
        .poll(() => offlineDatabases(page))
        .toContain('punchcard-offline');

    await page.goto('/business');
    await page.getByRole('button', { name: 'Toggle sidebar' }).click();
    await page.getByRole('button', { name: /Yasmine Alaoui/ }).click();
    await page.getByRole('menuitem', { name: 'Log out' }).click();
    await page.waitForURL((url) => url.pathname === '/');

    await expect
        .poll(() => offlineDatabases(page))
        .not.toContain('punchcard-offline');
    expect(await page.evaluate(() => caches.has('me-cards'))).toBe(false);
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
