import { expect, test } from '@playwright/test';
import { freshTap, signIn } from './helpers';

/**
 * The owner switching a stamper off and on again (CHW-36, B12), on a phone:
 * Disable asks first, a tap on it is then refused, and Enable gives it back.
 *
 * Needs the demo data (php artisan db:seed): Café Hafa's owner
 * (owner@cafe.demo.test) and its stamper 2, Terrace bar, which no other
 * test taps; and a SUN master key (NFC_SUN_MASTER_KEY) for the tap. It
 * leaves the stamper on.
 */
test.skip(
    ({ browserName }) => browserName !== 'webkit',
    'Runs on mobile-safari only.',
);

test('the owner switches a stamper off, a tap on it is refused, and switches it on again', async ({
    page,
}) => {
    test.setTimeout(60_000);
    await signIn(page, 'owner@cafe.demo.test');
    await page.goto('/business/stampers');

    const row = page
        .locator('[data-test="stamper-row"]')
        .filter({ hasText: 'Terrace bar' });
    await row.locator('[data-test="disable-stamper"]').click();
    await page.locator('[data-test="confirm-disable-stamper"]').click();
    await expect(row.getByText('Disabled', { exact: true })).toBeVisible();
    await expect(row.locator('[data-test="enable-stamper"]')).toBeVisible();

    const tap = await page.context().newPage();
    await tap.goto(freshTap(2));
    await expect(
        tap.getByText(
            'This stamper is not available right now. Ask the staff.',
        ),
    ).toBeVisible();
    await tap.close();

    await page.goto('/business/stampers');
    await expect(
        page
            .locator('[data-test="refused-tap"]')
            .filter({ hasText: 'Stamper switched off' })
            .first(),
    ).toBeVisible();

    await row.locator('[data-test="enable-stamper"]').click();
    await expect(row.getByText('Enabled', { exact: true })).toBeVisible();
    await expect(row.locator('[data-test="disable-stamper"]')).toBeVisible();
});
