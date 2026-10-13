import { expect, test } from '@playwright/test';
import type { CDPSession, Page } from '@playwright/test';
import { freshTap, signUp } from './helpers';

/**
 * The installable apps (CHW-35): on a phone, a café's join page and an
 * owner's dashboard each register the service worker for the whole site,
 * come under its control, and are installable: Chrome finds no problem
 * with the page's manifest, icons or service worker. Lighthouse 12 dropped
 * its PWA audits, so this asks Chrome itself (CDP), on mobile-chrome only.
 *
 * Needs the demo data (php artisan db:seed) and a production build: the
 * service worker is built with the assets (public/sw.js). The install sheet
 * also needs a SUN master key (NFC_SUN_MASTER_KEY), for punchcard:fake-tap.
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

test('a sign-out leaves no cards on the phone, not even behind Back', async ({
    page,
}) => {
    const hello = page.getByRole('heading', { level: 1, name: /^Hello/ });
    await signIn(page, 'owner@cafe.demo.test');
    await page.goto('/app');
    await expect(hello).toBeVisible();
    await expect
        .poll(() => offlineDatabases(page))
        .toContain('punchcard-offline');

    // Signed out from Settings, reached in the same page (Inertia keeps My cards in its history).
    await page
        .getByRole('navigation', { name: 'Main' })
        .getByRole('link', { name: 'Profile' })
        .click();
    await page.waitForURL(/\/settings\//);
    await page.getByRole('button', { name: 'Toggle sidebar' }).click();
    await page.getByRole('button', { name: /Yasmine Alaoui/ }).click();
    await page.getByRole('menuitem', { name: 'Log out' }).click();
    await page.waitForURL((url) => url.pathname === '/');
    await expect
        .poll(() => offlineDatabases(page))
        .not.toContain('punchcard-offline');

    // Back asks the server again, which sends a guest to sign in: the cards are neither shown nor kept.
    await page.goBack();
    await page.goBack();
    await page.waitForURL(/\/login$/);
    await expect(hello).toHaveCount(0);
    expect(await offlineDatabases(page)).not.toContain('punchcard-offline');
});

test('the cards kept are dropped when the session ended elsewhere', async ({
    page,
}) => {
    await signIn(page, 'owner@cafe.demo.test');
    await page.goto('/app');
    await expect
        .poll(() => offlineDatabases(page))
        .toContain('punchcard-offline');

    // The session ends without this phone signing out (it expired, or the password changed elsewhere).
    await page.context().clearCookies();
    await page.goto('/login');

    await expect
        .poll(() => offlineDatabases(page))
        .not.toContain('punchcard-offline');
});

test('offline with no cards kept, My cards says so', async ({ page }) => {
    // A phone that never kept any cards (or signed out): the offline page has nothing to show but a way back.
    await page.goto('/offline');

    await expect(
        page.getByRole('heading', { level: 1, name: "You're offline" }),
    ).toBeVisible();
    await expect(page.getByRole('button', { name: 'Try again' })).toBeVisible();
});

test.describe('on a phone in Arabic', () => {
    test.use({ locale: 'ar-MA' });

    test('offline with no cards kept, My cards says so in its language', async ({
        page,
    }) => {
        await page.goto('/offline');

        await expect(
            page.getByRole('heading', { level: 1, name: 'أنت غير متصل' }),
        ).toBeVisible();
        await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
        await expect(page.locator('html')).toHaveAttribute('lang', 'ar');
    });
});

test('offline, a tap on My cards in the app shows the cards kept on the phone', async ({
    page,
}) => {
    const tab = (name: string) =>
        page
            .getByRole('navigation', { name: 'Main' })
            .getByRole('link', { name });
    await signIn(page, 'owner@cafe.demo.test');
    await page.goto('/app');
    await serviceWorkerScope(page);
    await tab('Rewards').click();
    await page.waitForURL(/\/rewards$/);

    // Offline (the service worker too), the Inertia visit becomes a page load, which the worker answers with the
    // offline page.
    await page.evaluate(() => Object.assign(window, { sameDocument: true }));
    await page.context().setOffline(true);
    await tab('Cards').click();
    await page.waitForURL(/\/app$/);
    await expect(page.getByRole('status')).toContainText(
        'Offline. Last updated',
    );
    await expect(
        page.getByRole('heading', { level: 1, name: /^Hello/ }),
    ).toBeVisible();
    expect(await page.evaluate(() => 'sameDocument' in window)).toBe(false);

    // Back online, the page itself.
    await page.context().setOffline(false);
    await page.reload();
    await expect(page.getByRole('status')).toHaveCount(0);
});

test.describe('in dark mode', () => {
    test.use({ colorScheme: 'dark' });

    test('from the second visit on, the stamp screen offers to install the app', async ({
        page,
    }, testInfo) => {
        // Chrome offers its prompt once it judges the app installable; stood in for here, the same on every run.
        await page.addInitScript(() => {
            const prompted = { count: 0 };
            Object.assign(window, { prompted });
            window.addEventListener(
                'beforeinstallprompt',
                (event) => {
                    if (event.isTrusted) {
                        event.stopImmediatePropagation();
                    }
                },
                true,
            );
            window.addEventListener('load', () =>
                window.dispatchEvent(
                    Object.assign(
                        new Event('beforeinstallprompt', { cancelable: true }),
                        {
                            prompt: () => {
                                prompted.count += 1;

                                return Promise.resolve();
                            },
                            userChoice: Promise.resolve({
                                outcome: 'accepted',
                            }),
                        },
                    ),
                ),
            );
        });
        const sheet = page.getByRole('dialog', {
            name: 'Keep punchcard on your home screen',
        });

        // A new customer's first visit (Café Hafa): no sheet.
        await page.goto(freshTap(3));
        await signUp(page);
        // Signing up, then claiming the tap: slow on a single-process dev server.
        await expect(
            page.getByRole('heading', { name: '1 of 10' }),
        ).toBeVisible({ timeout: 20_000 });
        await page.waitForTimeout(2_000);
        await expect(sheet).toHaveCount(0);

        // The second, at a café of another program (its own card, so no cooldown).
        await page.goto(freshTap(5));
        await expect(sheet).toBeVisible();
        await expect(sheet.getByRole('listitem')).toHaveCount(0);
        await testInfo.attach('install-sheet-android-dark', {
            body: await page.screenshot(),
            contentType: 'image/png',
        });

        await sheet.getByRole('button', { name: 'Install' }).click();
        await expect(sheet).toBeHidden();
        expect(
            await page.evaluate(
                () =>
                    (window as unknown as { prompted: { count: number } })
                        .prompted.count,
            ),
        ).toBe(1);
    });
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
