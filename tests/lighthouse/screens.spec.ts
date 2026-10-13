import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { chromium, expect, test } from '@playwright/test';
import type { BrowserContext, Page } from '@playwright/test';
import lighthouse from 'lighthouse';
import { freshTap, signIn, signUp } from '../e2e/helpers';

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
test.describe.configure({ mode: 'serial' });

const MIN_ACCESSIBILITY = 0.9;
const PORT = 9333;

let profile: string;
let context: BrowserContext;

// Lighthouse drives this same browser through its debugging port, in the same profile: the session carries over.
test.beforeEach(async () => {
    profile = mkdtempSync(path.join(tmpdir(), 'punchcard-lighthouse-'));
    context = await chromium.launchPersistentContext(profile, {
        baseURL: test.info().project.use.baseURL,
        args: [`--remote-debugging-port=${PORT}`],
    });
});

test.afterEach(async () => {
    await context.close();
    rmSync(profile, { recursive: true, force: true });
});

/** Checks the screen the page shows: installable, then Lighthouse's accessibility score (and performance, reported). */
async function audit(page: Page, screen: string): Promise<void> {
    const cdp = await context.newCDPSession(page);
    const { installabilityErrors } = await cdp.send(
        'Page.getInstallabilityErrors',
    );
    expect
        .soft(
            installabilityErrors.map((error) => error.errorId),
            `${screen} is installable`,
        )
        .toEqual([]);

    // The same page again in a new tab, keeping the session (disableStorageReset).
    const result = await lighthouse(page.url(), {
        port: PORT,
        output: 'html',
        onlyCategories: ['accessibility', 'performance'],
        disableStorageReset: true,
        logLevel: 'error',
    });
    expect(result, `Lighthouse ran on ${screen}`).toBeDefined();
    const { lhr, report } = result!;
    const score = (category: string) =>
        Math.round((lhr.categories[category]?.score ?? 0) * 100);

    // What fell short, by audit id, to look up in the attached report.
    const shortfalls = (lhr.categories.accessibility?.auditRefs ?? [])
        .filter((ref) => ref.weight > 0 && (lhr.audits[ref.id]?.score ?? 1) < 1)
        .map((ref) => ref.id);
    const summary = `accessibility ${score('accessibility')}, performance ${score('performance')}${shortfalls.length > 0 ? `; short on ${shortfalls.join(', ')}` : ''}`;

    await test.info().attach(`${screen}.html`, {
        body: String(report),
        contentType: 'text/html',
    });
    test.info().annotations.push({ type: screen, description: summary });
    console.log(`${screen}: ${summary}`);
    expect
        .soft(score('accessibility'), `${screen} accessibility`)
        .toBeGreaterThanOrEqual(MIN_ACCESSIBILITY * 100);
}

test('C2 the stamp screen and C5 My cards', async () => {
    const page = await context.newPage();

    // A new customer's first tap: signed up, then stamped.
    await page.goto(freshTap());
    await signUp(page);
    await expect(page.getByRole('heading', { name: '1 of 10' })).toBeVisible({
        timeout: 20_000,
    });
    await audit(page, 'C2 stamp screen');

    await page.goto('/app');
    await expect(
        page.getByRole('heading', { level: 1, name: /^Hello/ }),
    ).toBeVisible();
    await audit(page, 'C5 My cards');
});

test("B3 the owner's dashboard", async () => {
    const page = await context.newPage();

    await signIn(page, 'owner@cafe.demo.test');
    await expect(page).toHaveURL(/\/business$/);
    await audit(page, 'B3 dashboard');
});
