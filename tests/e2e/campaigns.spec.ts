import { expect, test } from '@playwright/test';
import { signIn } from './helpers';

/**
 * The Campaigns page (CHW-42, design 12c), on a phone: the owner reaches it
 * from the sidebar and sees the campaigns Café Hafa sent, newest first, and
 * how many of the week's are left.
 *
 * Needs the demo data (php artisan db:seed): Café Hafa's owner
 * (owner@cafe.demo.test) and its two sent campaigns. Reads only.
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
    // How many are left moves with the days since the demo was seeded: not the count.
    await expect(
        page.getByText(/of 3 campaigns left this week\./),
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

test('the owner composes a campaign and sees it on a lock screen; nobody to send to, Send waits', async ({
    page,
}, testInfo) => {
    await signIn(page, 'owner@cafe.demo.test');
    await page.goto('/business/campaigns');
    await page.locator('[data-test="new-campaign"]').click();
    await expect(page).toHaveURL(/\/business\/campaigns\/create$/);

    await page.locator('[data-test="audience-top_10"]').click();
    await expect(page.locator('[data-test="audience-top_10"]')).toHaveAttribute(
        'aria-checked',
        'true',
    );
    await page.locator('#title').fill('Slow Tuesday?');
    await page.locator('#body').fill('Two stamps for every coffee until 4 pm.');

    const preview = page.locator('[data-test="lock-preview"]');
    await expect(preview).toContainText('Café Hafa · Slow Tuesday?');
    await expect(preview).toContainText(
        'Two stamps for every coffee until 4 pm.',
    );

    // The demo has no VAPID key, so no phone gets notifications: nothing to send to.
    await expect(page.locator('[data-test="campaign-reach"]')).toHaveText(
        'None of them has notifications on a phone yet.',
    );
    await expect(page.locator('[data-test="campaign-send"]')).toBeDisabled();

    await testInfo.attach('composer', {
        body: await page.screenshot({ fullPage: true }),
        contentType: 'image/png',
    });
});
