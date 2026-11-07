import { expect, test } from './owner';

/**
 * A campaign's results (CHW-48, B10, design 19a), on a phone: the owner
 * opens this week's campaign from Campaigns, sees what it did, and sets the
 * average ticket the estimated revenue reads; the week before's was sent
 * before results were recorded.
 *
 * Needs the demo data (php artisan db:seed): Café Hafa's owner
 * (owner@cafe.demo.test), "Slow Tuesday?" sent to 6 customers, 2 of whom
 * opened it, and "Rainy day tea" from before results. Sets the ticket to
 * 35 dirhams, whatever it was: a run again finds the same.
 */
test.skip(
    ({ browserName }) => browserName !== 'webkit',
    'Runs on mobile-safari only.',
);

test("the owner opens a campaign's results from Campaigns and sets the average ticket", async ({
    page,
}, testInfo) => {
    await page.goto('/business/campaigns');
    await page
        .locator('[data-test="campaign-results"]', { hasText: 'Slow Tuesday?' })
        .click();

    await expect(page).toHaveURL(/\/business\/campaigns\/\d+$/, {
        timeout: 20_000,
    });
    await expect(page.locator('[data-test="campaign-sent"]')).toContainText(
        '6',
    );
    await expect(page.locator('[data-test="campaign-opened"]')).toContainText(
        '33%',
    );
    await expect(page.locator('[data-test="campaign-compared"]')).toContainText(
        'Same 48 h a week before',
    );

    // The ticket, set or changed: 35 dirhams.
    const revenue = page.locator('[data-test="campaign-revenue"]');
    const change = revenue.locator('[data-test="change-ticket"]');

    if (await change.isVisible()) {
        await change.click();
    }

    await revenue.locator('[data-test="average-ticket"]').fill('35');
    await revenue.locator('[data-test="save-ticket"]').click();
    await expect(page.getByText('Average ticket saved.')).toBeVisible({
        timeout: 20_000,
    });
    await expect(revenue).toContainText(/35.* average ticket/);

    await testInfo.attach('campaign-results', {
        body: await page.screenshot({ fullPage: true }),
        contentType: 'image/png',
    });

    // The week before's: sent before results were recorded.
    await page.goto('/business/campaigns');
    await page
        .locator('[data-test="campaign-results"]', { hasText: 'Rainy day tea' })
        .click();
    await expect(
        page.locator('[data-test="campaign-before-results"]'),
    ).toBeVisible({ timeout: 20_000 });
});
