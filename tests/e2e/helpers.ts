import { execFileSync } from 'node:child_process';
import type { Page } from '@playwright/test';

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

/** Signs up a new customer from a first tap's page (C1), in English. */
export async function signUp(page: Page): Promise<void> {
    await page.getByRole('link', { name: 'Continue with email' }).click();
    await page.locator('#name').fill('Salma Tapper');
    await page.locator('#email').fill(`tap-${Date.now()}@example.com`);
    await page.locator('#password').fill('a-long-test-password-1');
    await page.locator('#password_confirmation').fill('a-long-test-password-1');
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
