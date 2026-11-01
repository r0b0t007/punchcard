import { expect, test } from '@playwright/test';
import { signIn } from './helpers';

/**
 * The Campaigns page (CHW-42, design 12c), on a phone: the owner reaches it
 * from the sidebar and sees the campaigns Café Hafa sent, newest first, and
 * how many of the week's are left.
 *
 * Needs the demo data (php artisan db:seed): Café Hafa's owner
 * (owner@cafe.demo.test) and its two campaigns, one 2 days ago, one 9 days
 * ago. Reads only.
 */
test.skip(
    ({ browserName }) => browserName !== 'webkit',
    'Runs on mobile-safari only.',
);

test('the owner opens Campaigns from the sidebar and sees what was sent', async ({
    page,
}, testInfo) => {
    await signIn(page, 'owner@cafe.demo.test');
    await page.goto('/business');
    await page.getByRole('button', { name: 'Toggle sidebar' }).click();
    await page.getByRole('link', { name: 'Campaigns' }).click();

    await expect(page).toHaveURL(/\/business\/campaigns$/);
    await expect(
        page.getByText('2 of 3 campaigns left this week.'),
    ).toBeVisible();

    const rows = page.locator('[data-test="campaign-row"]');
    await expect(rows).toHaveCount(2);
    await expect(rows.nth(0)).toContainText('Slow Tuesday?');
    await expect(rows.nth(0)).toContainText('Not seen in 14 days');
    await expect(rows.nth(0)).toContainText('6 people');
    await expect(rows.nth(1)).toContainText('Rainy day tea');
    await expect(rows.nth(1)).toContainText('Top 10');

    await testInfo.attach('campaigns', {
        body: await page.screenshot({ fullPage: true }),
        contentType: 'image/png',
    });
});
