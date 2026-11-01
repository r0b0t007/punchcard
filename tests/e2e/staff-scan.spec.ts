import { execFileSync } from 'node:child_process';
import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import { loginLink, signIn } from './helpers';

/**
 * Staff scanning a customer's member QR (CHW-30, S2, S3), on a phone: the
 * customer opens My QR, staff type its short code (headless browsers have
 * no camera), add two stamps, then redeem the customer's reward, which
 * My cards then tells the customer about (CHW-228).
 *
 * Needs the demo data (php artisan db:seed): Café Hafa's staff member
 * (staff@cafe.demo.test) and its stamper 1. A new customer each run, so
 * neither the cooldown nor the one-scan-per-code rule meets a previous run.
 */
test.skip(
    ({ browserName }) => browserName !== 'webkit',
    'Runs on mobile-safari only.',
);

/** Signs up a new customer by email link, in English (CHW-23): their email. */
async function newCustomer(page: Page): Promise<string> {
    const email = `scan-${Date.now()}@example.com`;

    await page.goto('/login/email');
    await page.locator('#email').fill(email);
    await page.getByRole('button', { name: 'Email me a sign-in link' }).click();
    await page.getByRole('heading', { name: 'Check your inbox' }).waitFor();
    await page.goto(loginLink(email));
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.locator('#name').fill('Salma Scanner');
    await page.getByRole('checkbox', { name: "I'm 16 or older" }).check();
    await page.locator('[data-test="finish-sign-up-button"]').click();
    await page.waitForURL((url) => !url.pathname.startsWith('/sign-up'));

    return email;
}

test("staff type a customer's short code, add two stamps and redeem their reward", async ({
    browser,
}) => {
    // Two people signing in, and two artisan calls: longer than the default 30 s on a cold server.
    test.setTimeout(90_000);

    const customerContext = await browser.newContext();
    const customer = await customerContext.newPage();
    const email = await newCustomer(customer);

    // A Free mint tea at Café Hafa (stamper 1's café), to redeem below.
    execFileSync('php', ['artisan', 'punchcard:fake-reward', email, '1'], {
        encoding: 'utf8',
    });

    await customer.goto('/app/qr');
    const shortCode = customer.locator('[data-test="member-short-code"]');
    await expect(shortCode).toHaveText(/^\d+ · \d{3} \d{3}$/);

    const staffContext = await browser.newContext();
    const staff = await staffContext.newPage();
    await signIn(staff, 'staff@cafe.demo.test');
    await staff.goto('/business/counter');
    await staff.locator('[data-test="counter-scan"]:visible').click();
    await staff.waitForURL('**/business/counter/scan');

    // Read just before typing: a code lasts its 30 s window, and the previous one for 10 s more.
    const code = (await shortCode.innerText()).replace(/\D+/g, '');
    await staff.locator('[data-test="scan-code"]').fill(code);
    await staff.locator('[data-test="scan-code"]').press('Enter');

    await expect(staff.locator('[data-test="scan-name"]')).toHaveText('Salma');

    await staff.getByRole('button', { name: '+2', exact: true }).click();
    await expect(staff.locator('[data-test="scan-qty"]')).toHaveText('2');
    await staff.locator('[data-test="scan-add"]').click();
    await expect(staff.locator('[data-test="scan-outcome"]')).toContainText(
        '+2',
    );

    // By data-test: the demo staff account may read the app in French or Arabic.
    await staff.locator('[data-test="scan-redeem"]').first().click();
    await staff.locator('[data-test="scan-confirm-redeem"]').click();
    await expect(staff.locator('[data-test="scan-redeemed"]')).toContainText(
        'Free mint tea',
    );

    // The customer is told (CHW-228): no phone of theirs gets notifications, so My cards says so until Got it.
    await customer.goto('/app');
    const notice = customer.locator('[data-test="redeem-notice"]');
    await expect(notice).toContainText('Free mint tea');
    await expect(notice).toContainText('Café Hafa');
    await notice.locator('[data-test="redeem-notice-dismiss"]').click();
    await expect(notice).toHaveCount(0);

    await customerContext.close();
    await staffContext.close();
});
