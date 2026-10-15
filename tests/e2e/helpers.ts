import { execFileSync } from 'node:child_process';
import type { CDPSession, Page } from '@playwright/test';

/**
 * A fresh tap URL (path and query) from a demo stamper, as the stamper would
 * write it: punchcard:fake-tap, which needs the demo data and a SUN master
 * key (NFC_SUN_MASTER_KEY). Stampers 1 to 3 are Café Hafa's, 4 Atlas Coffee
 * Tangier's and 5 Atlas Coffee Tétouan's.
 */
export function freshTap(stamper = 1): string {
    const output = execFileSync(
        'php',
        ['artisan', 'punchcard:fake-tap', String(stamper)],
        { encoding: 'utf8' },
    );
    const url = new URL(output.trim().split(/\s+/).pop() ?? '');

    return `${url.pathname}${url.search}`;
}

/**
 * A working email sign-in link (path and query) for an address, as the mail
 * carries it (CHW-23): punchcard:login-link, since a test can't read mail.
 */
export function loginLink(email: string): string {
    const output = execFileSync(
        'php',
        ['artisan', 'punchcard:login-link', email],
        { encoding: 'utf8' },
    );
    const url = new URL(output.trim().split(/\s+/).pop() ?? '');

    return `${url.pathname}${url.search}`;
}

/**
 * Signs up a new customer from a first tap's page (C1), in English, as a
 * customer would (CHW-23): Continue with email, the link in their inbox,
 * then their name and that they are 16 or older.
 */
export async function signUp(page: Page): Promise<void> {
    const email = `tap-${Date.now()}@example.com`;

    await page.getByRole('link', { name: 'Continue with email' }).click();
    await page.locator('#email').fill(email);
    await page.getByRole('button', { name: 'Email me a sign-in link' }).click();
    await page.getByRole('heading', { name: 'Check your inbox' }).waitFor();

    await page.goto(loginLink(email));
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.locator('#name').fill('Salma Tapper');
    await page.getByRole('checkbox', { name: "I'm 16 or older" }).check();
    await page.locator('[data-test="finish-sign-up-button"]').click();
}

/** Fills and sends the password sign-up form (/register), as a new customer: 16 or older (CHW-23). */
export async function fillSignUp(page: Page): Promise<void> {
    await page.locator('#name').fill('Salma Tapper');
    await page.locator('#email').fill(`tap-${Date.now()}@example.com`);
    await page.locator('#password').fill('a-long-test-password-1');
    await page.locator('#password_confirmation').fill('a-long-test-password-1');
    await page.getByRole('checkbox', { name: "I'm 16 or older" }).check();
    await page.locator('[data-test="register-user-button"]').click();
}

/** Signs in a demo account (DemoSeeder: password "password"). Fortify allows five sign-ins a minute per account. */
export async function signIn(page: Page, email: string): Promise<void> {
    await page.goto('/login');
    await page.locator('#email').fill(email);
    await page.locator('#password').fill('password');
    await page.locator('[data-test="login-button"]').click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
}

const sessions = new WeakMap<Page, CDPSession>();

/** The page's Chrome DevTools Protocol session, one per page (Chromium only). */
export async function cdp(page: Page): Promise<CDPSession> {
    let session = sessions.get(page);

    if (!session) {
        session = await page.context().newCDPSession(page);
        sessions.set(page, session);
    }

    return session;
}

/**
 * What keeps Chrome from installing the page's app, by error id: none when
 * installable. Chrome's own check (CDP), which Lighthouse 12 dropped.
 */
export async function installabilityErrors(page: Page): Promise<string[]> {
    const { installabilityErrors } = await (
        await cdp(page)
    ).send('Page.getInstallabilityErrors');

    return installabilityErrors.map((error) => error.errorId);
}
