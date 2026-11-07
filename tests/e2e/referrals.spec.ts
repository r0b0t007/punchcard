import type { Page } from '@playwright/test';
import { signIn } from './helpers';
import { expect, test } from './owner';

/**
 * Refer a friend (CHW-47, designs 20a and 20c), on a phone: Café Hafa's
 * owner turns it on for the Hafa card in the card builder, and the demo
 * customer's card offers their link with this month's stamps from friends.
 *
 * Needs the demo data (php artisan db:seed): Café Hafa's owner
 * (owner@cafe.demo.test), its Hafa card, and the demo customer
 * (customer@demo.test), who holds it. Turns referrals off again at the
 * end, as the demo data has them, so other specs find the card unchanged.
 */
test.skip(
    ({ browserName }) => browserName !== 'webkit',
    'Runs on mobile-safari only.',
);

/** Opens the Hafa card in the card builder and sets its referrals switch, then saves. */
async function setReferrals(page: Page, on: boolean) {
    await page.goto('/business/cards');
    await page
        .getByRole('listitem')
        .filter({ hasText: 'Hafa card' })
        .getByRole('link', { name: 'Edit' })
        .click();
    await expect(page).toHaveURL(/\/business\/cards\/\d+\/edit$/, {
        timeout: 20_000,
    });

    const referrals = page.locator('[data-test="referrals-switch"]');

    if ((await referrals.getAttribute('aria-checked')) !== String(on)) {
        await referrals.click();
    }

    await expect(referrals).toHaveAttribute('aria-checked', String(on));
    await expect(page.locator('#referral_monthly_cap')).toHaveCount(on ? 1 : 0);
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page).toHaveURL(/\/business\/cards$/, { timeout: 20_000 });
}

test('the owner turns refer-a-friend on, and the customer gets their link on their card', async ({
    page,
    browser,
}, testInfo) => {
    await setReferrals(page, true);

    try {
        // The customer, on their own phone.
        const phone = await browser.newPage({
            baseURL: testInfo.project.use.baseURL,
            storageState: { cookies: [], origins: [] },
            viewport: { width: 390, height: 844 },
        });
        await signIn(phone, 'customer@demo.test');
        await phone.goto('/app');
        await phone
            .getByRole('link', { name: /Café Hafa/ })
            .first()
            .click();
        await expect(phone).toHaveURL(/\/app\/cards\/\d+$/, {
            timeout: 20_000,
        });

        const panel = phone.locator('[data-test="referral-panel"]');
        await expect(panel).toBeVisible();
        await expect(panel).toContainText('Invite a friend');
        await expect(
            phone.locator('[data-test="referral-month"]'),
        ).toContainText(/this month/);
        await expect(
            phone.locator('[data-test="copy-referral"]'),
        ).toBeVisible();

        await testInfo.attach('card-referral-panel', {
            body: await phone.screenshot({ fullPage: true }),
            contentType: 'image/png',
        });
        await phone.close();
    } finally {
        // Off again, the limit's field gone with it: saving still works.
        await setReferrals(page, false);
    }
});
