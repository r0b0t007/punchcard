import { expect, test } from '@playwright/test';
import { signIn } from './helpers';

/**
 * The Customers page (CHW-44, B4, design 15a), on a phone: the owner reaches
 * it from the sidebar, sees Café Hafa's customers by first name and
 * initial, switches tab, and finds one by first name.
 *
 * Needs the demo data (php artisan db:seed), summarised by the seeder:
 * Café Hafa's owner (owner@cafe.demo.test) and its customers, among them
 * the demo customer Salma Bennani. Reads only.
 */
test.skip(
    ({ browserName }) => browserName !== 'webkit',
    'Runs on mobile-safari only.',
);

test('the owner opens Customers from the sidebar, switches tab and finds a customer', async ({
    page,
}, testInfo) => {
    await signIn(page, 'owner@cafe.demo.test');
    await page.goto('/business');
    await page.getByRole('button', { name: 'Toggle sidebar' }).click();
    await page.getByRole('link', { name: 'Customers' }).click();

    await expect(page).toHaveURL(/\/business\/customers$/);
    // The phone's sidebar sheet stays open over the page it opened: close it first.
    await page.keyboard.press('Escape');

    // 20 in a fresh demo; other specs sign new customers up at the café, so at least that many.
    const rows = page.locator('[data-test="customer-row"]');
    const all = Number(
        (
            await page.locator('[data-test="customers-tab-all"]').innerText()
        ).replace(/\D+/g, ''),
    );
    expect(all).toBeGreaterThanOrEqual(20);
    await expect(rows).toHaveCount(Math.min(all, 25));

    await testInfo.attach('customers', {
        body: await page.screenshot({ fullPage: true }),
        contentType: 'image/png',
    });

    // Who is in a tab moves with the days since the demo was seeded: the tab, not its rows.
    await page.locator('[data-test="customers-tab-quiet"]').click();
    await expect(page).toHaveURL(/segment=quiet/);
    await expect(
        page.locator('[data-test="customers-tab-quiet"]'),
    ).toHaveAttribute('aria-current', 'page');

    await page.locator('[data-test="customers-tab-all"]').click();
    await expect(
        page.locator('[data-test="customers-tab-all"]'),
    ).toHaveAttribute('aria-current', 'page');
    await page.locator('[data-test="customers-search"]').fill('Salma');
    await expect(page).toHaveURL(/q=Salma/);
    // The demo customer, among others called Salma that the scan spec signs up.
    await expect(rows.filter({ hasText: 'Salma B.' })).toHaveCount(1);
    await expect(rows.filter({ hasNotText: 'Salma' })).toHaveCount(0);
});
