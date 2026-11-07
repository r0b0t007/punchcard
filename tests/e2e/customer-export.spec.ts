import { expect, test } from './owner';

/**
 * Exporting customers and stamps (CHW-49, design 21), on a phone: Café
 * Hafa's owner opens Export on the Customers page, keeps the last 90 days
 * and asks for it; the page says where the link goes. The file and its
 * link are covered by the feature tests (no mailbox here).
 *
 * Needs the demo data (php artisan db:seed): Café Hafa's owner
 * (owner@cafe.demo.test). Asks for one export a run (3 an hour).
 */
test.skip(
    ({ browserName }) => browserName !== 'webkit',
    'Runs on mobile-safari only.',
);

test('the owner asks for an export of their customers and stamps', async ({
    page,
}, testInfo) => {
    await page.goto('/business/customers');
    await page.locator('[data-test="customers-export"]').click();

    const from = page.locator('[data-test="export-from"]');
    const to = page.locator('[data-test="export-to"]');
    await expect(from).toHaveValue(/^\d{4}-\d{2}-\d{2}$/);
    await expect(to).toHaveValue(/^\d{4}-\d{2}-\d{2}$/);
    await testInfo.attach('export-dialog', {
        body: await page.screenshot({ fullPage: true }),
        contentType: 'image/png',
    });

    await page.locator('[data-test="export-send"]').click();

    const sent = page.locator('[data-test="customers-export-sent"]');
    await expect(sent).toBeVisible({ timeout: 20_000 });
    await expect(sent).toContainText('***@');
    await testInfo.attach('export-sent', {
        body: await page.screenshot({ fullPage: true }),
        contentType: 'image/png',
    });
});
