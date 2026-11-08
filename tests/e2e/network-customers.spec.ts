import { expect, test } from '@playwright/test';
import { signIn } from './helpers';

/**
 * A franchise's customers for its head office (CHW-365, design 23), on a
 * phone: Atlas Coffee's org admin opens Customers, sees customers of both
 * franchisees (some of each, some of both) with the businesses they visit,
 * and opens one who visits both: every site's history, read only.
 *
 * Needs the demo data (php artisan db:seed): Atlas Coffee's head office
 * (hq@franchise.demo.test), two franchisees and four customers of both.
 */
test.skip(
    ({ browserName }) => browserName !== 'webkit',
    'Runs on mobile-safari only.',
);

test('the head office sees its customers across its sites, read only', async ({
    page,
}, testInfo) => {
    await signIn(page, 'hq@franchise.demo.test');
    await page.goto('/business/customers');

    const rows = page.locator('[data-test="customer-row"]');
    await expect(rows.first()).toBeVisible();
    const both = rows
        .filter({ hasText: 'Tangier' })
        .filter({ hasText: 'Tétouan' });
    await expect(both.first()).toBeVisible();
    await testInfo.attach('network-customers', {
        body: await page.screenshot({ fullPage: true }),
        contentType: 'image/png',
    });

    await both.first().locator('a').click();
    await expect(
        page.locator('[data-test="customer-read-only"]'),
    ).toBeVisible();
    await expect(page.locator('[data-test="add-stamps"]')).toHaveCount(0);
    await expect(page.locator('[data-test="customer-note-body"]')).toHaveCount(
        0,
    );
    await expect(
        page
            .locator('[data-test="customer-event"] [data-test="business-tag"]')
            .first(),
    ).toBeVisible();
    await testInfo.attach('network-customer', {
        body: await page.screenshot({ fullPage: true }),
        contentType: 'image/png',
    });
});
