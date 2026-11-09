import { expect, test } from '@playwright/test';
import { signIn } from './helpers';

/**
 * The brand page (CHW-135, B17, design 24), on a phone: Café Hafa's owner
 * opens Brand, types a colour no text reads on, takes the nearby shade the
 * page offers, sees the card preview and the hex field change, and saves it.
 * Then it goes back to punchcard's colour ("Default"), as the demo had it, so
 * every run starts from the same data.
 *
 * Needs the demo data (php artisan db:seed): Café Hafa's owner
 * (owner@cafe.demo.test).
 */
test.skip(
    ({ browserName }) => browserName !== 'webkit',
    'Runs on mobile-safari only.',
);

test('the owner picks a readable brand colour and saves it', async ({
    page,
}, testInfo) => {
    await signIn(page, 'owner@cafe.demo.test');
    await page.goto('/business/brand');

    const card = page
        .getByRole('group', { name: /Café Hafa loyalty card/ })
        .first();
    await expect(card).toBeVisible();

    const hex = page.locator('#brand_color_custom');
    await hex.fill('#9A7564');
    const weak = page.locator('[data-test="weak-colour"]');
    await expect(weak).toBeVisible();
    await weak.getByRole('button').click();
    await expect(weak).toHaveCount(0);
    await expect(hex).toHaveValue('#9F7C6C');
    await expect(card).toHaveCSS('background-color', 'rgb(159, 124, 108)');
    await testInfo.attach('brand', {
        body: await page.screenshot({ fullPage: true }),
        contentType: 'image/png',
    });

    const save = page.locator('[data-test="save-brand-colour"]');
    await save.click();
    await expect(page.getByText('Brand colour saved.')).toBeVisible();
    await expect(save).toBeDisabled();

    // Back to punchcard's espresso.
    await page.getByRole('button', { name: 'Default' }).click();
    await expect(hex).toHaveValue('');
    await expect(card).toHaveCSS('background-color', 'rgb(59, 42, 32)');
    await save.click();
    await expect(save).toBeDisabled();
});
