import { existsSync, mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { chromium, devices, expect, test } from '@playwright/test';
import type { BrowserContext, Page } from '@playwright/test';
import lighthouse from 'lighthouse';
import {
    fillSignUp,
    freshTap,
    installabilityErrors,
    signIn,
} from '../e2e/helpers';

/**
 * Lighthouse on the screens that matter most (CHW-35, pwa-conventions
 * skill), signed in, as a phone sees them: C2 the stamp screen, C5 My cards
 * and B3 the owner's dashboard. Each must be installable, by Chrome's own
 * check (Lighthouse 12 dropped its PWA audits), and score at least 90 for
 * accessibility. Performance is reported, not enforced: the app runs on
 * `php artisan serve` here, nothing like production.
 *
 * The reports are attached to the HTML report (lighthouse-report/). Needs
 * the demo data (php artisan db:seed), a production build and a SUN master
 * key (NFC_SUN_MASTER_KEY), like the e2e specs.
 */
const MIN_ACCESSIBILITY = 0.9;

// The Pixel 7 the e2e specs use.
const { viewport, userAgent, deviceScaleFactor, isMobile, hasTouch } =
    devices['Pixel 7'];

let profile: string | undefined;
let context: BrowserContext | undefined;
let port: number;

// Lighthouse drives this same browser through its debugging port, in the same profile: the session carries over.
test.beforeEach(async () => {
    profile = mkdtempSync(path.join(tmpdir(), 'punchcard-lighthouse-'));
    context = await chromium.launchPersistentContext(profile, {
        viewport,
        userAgent,
        deviceScaleFactor,
        isMobile,
        hasTouch,
        baseURL: test.info().project.use.baseURL,
        // A free port, never another browser's: Chrome writes the one it took in the profile.
        args: ['--remote-debugging-port=0'],
    });

    const activePort = path.join(profile, 'DevToolsActivePort');
    await expect.poll(() => existsSync(activePort)).toBe(true);
    port = Number(readFileSync(activePort, 'utf8').split('\n')[0]);
});

test.afterEach(async () => {
    await context?.close();

    if (profile) {
        // Chrome may still hold its profile's files a moment after closing (Windows).
        rmSync(profile, { recursive: true, force: true, maxRetries: 5 });
    }

    context = undefined;
    profile = undefined;
});

/**
 * Runs Lighthouse on `url` in a new tab of the same session (it loads it
 * first: a tap URL is the stamp screen on its first look), then checks the
 * screen `page` shows at `shownAt` is installable, once its service worker
 * is ready, as in pwa.spec.
 */
async function audit(
    page: Page,
    screen: string,
    url: string,
    shownAt = url,
): Promise<void> {
    const result = await lighthouse(new URL(url, page.url()).href, {
        port,
        output: 'html',
        onlyCategories: ['accessibility', 'performance'],
        disableStorageReset: true,
        logLevel: 'error',
    });
    expect(result, `Lighthouse ran on ${screen}`).toBeDefined();
    const { lhr, report } = result!;
    expect(new URL(lhr.finalDisplayedUrl).pathname).toBe(
        new URL(shownAt, page.url()).pathname,
    );

    const accessibility = lhr.categories.accessibility?.score ?? 0;
    const performance = lhr.categories.performance?.score ?? 0;
    // What fell short, by audit id, to look up in the attached report (not-applicable ones have no score either).
    const shortfalls = (lhr.categories.accessibility?.auditRefs ?? [])
        .filter((ref) => ref.weight > 0)
        .flatMap((ref) => {
            const audit = lhr.audits[ref.id];

            if (audit?.scoreDisplayMode === 'error') {
                return [`${ref.id} (errored)`];
            }

            return typeof audit?.score === 'number' && audit.score < 1
                ? [ref.id]
                : [];
        });
    const summary = `accessibility ${Math.round(accessibility * 100)}, performance ${Math.round(performance * 100)}${shortfalls.length > 0 ? `; short on ${shortfalls.join(', ')}` : ''}`;

    await test.info().attach(`${screen}.html`, {
        body: String(report),
        contentType: 'text/html',
    });
    test.info().annotations.push({ type: screen, description: summary });
    console.log(`${screen}: ${summary}`);
    expect
        .soft(accessibility, `${screen} accessibility`)
        .toBeGreaterThanOrEqual(MIN_ACCESSIBILITY);

    await page.goto(shownAt);
    await page.evaluate(async () => {
        await navigator.serviceWorker.ready;
    });
    expect
        .soft(await installabilityErrors(page), `${screen} is installable`)
        .toEqual([]);
}

test('C2 the stamp screen and C5 My cards', async () => {
    const page = await context!.newPage();

    // A new customer, signed up first: the tap Lighthouse opens is their first stamp (the install sheet comes from
    // the second visit on), seen as on the phone, the stamp landing.
    await page.goto('/register');
    await fillSignUp(page);
    await page.waitForURL((url) => !url.pathname.startsWith('/register'), {
        timeout: 20_000,
    });

    await audit(page, 'C2 stamp screen', freshTap(), '/t/result');
    await expect(page.getByRole('heading', { name: '1 of 10' })).toBeVisible();

    await audit(page, 'C5 My cards', '/app');
    await expect(
        page.getByRole('heading', { level: 1, name: /^Hello/ }),
    ).toBeVisible();
});

test("B3 the owner's dashboard", async () => {
    const page = await context!.newPage();

    await signIn(page, 'owner@cafe.demo.test');
    await expect(page).toHaveURL(/\/business$/);
    await audit(page, 'B3 dashboard', '/business');
});
