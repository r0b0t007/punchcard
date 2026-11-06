import { expect, test } from './owner';

/**
 * The Automations page (CHW-46, B11, design 18a), on a phone: Café Hafa's
 * owner reaches it from the sidebar, turns the win-back on, writes its own
 * message and goes back to ours.
 *
 * Needs the demo data (php artisan db:seed): Café Hafa is allowed
 * automations there, all three off to start. Ends with the win-back on and
 * our message, so a run again starts the same way.
 */
test.skip(
    ({ browserName }) => browserName !== 'webkit',
    'Runs on mobile-safari only.',
);

test('the owner turns the win-back on and writes its own message, then goes back to ours', async ({
    page,
}, testInfo) => {
    await page.goto('/business');
    await page.getByRole('button', { name: 'Toggle sidebar' }).click();
    await page.getByRole('link', { name: 'Automations' }).click();

    await expect(page).toHaveURL(/\/business\/automations$/, {
        timeout: 20_000,
    });
    // The phone's sidebar sheet stays open over the page it opened: close it first.
    await page.keyboard.press('Escape');

    const winBack = page.locator('[data-test="automation-win_back"]');
    const toggle = page.locator('[data-test="automation-win_back-switch"]');
    await expect(page.locator('[data-test="automation-rules"]')).toContainText(
        'at most one of these a week',
    );

    // On, whatever it was: a run again finds it on from the run before.
    if ((await toggle.getAttribute('aria-checked')) !== 'true') {
        await toggle.click();
        await expect(page.getByText('Win-back is on.')).toBeVisible({
            timeout: 20_000,
        });
    }
    await expect(toggle).toHaveAttribute('aria-checked', 'true', {
        timeout: 20_000,
    });

    // Its own message, as written, titled with the café's name.
    await page.locator('[data-test="automation-win_back-edit"]').click();
    const editor = page.locator('[data-test="automation-editor"]');
    await editor.getByLabel('Title').fill('We miss you');
    await editor
        .getByLabel('Message')
        .fill('Two stamps on your next coffee, this week.');

    await testInfo.attach('automation-editor', {
        body: await page.screenshot({ fullPage: true }),
        contentType: 'image/png',
    });

    await editor.locator('[data-test="automation-save"]').click();
    // The save and the page's reload run in the request: give it time.
    await expect(editor).toBeHidden({ timeout: 20_000 });
    await expect(winBack).toContainText('Café Hafa · We miss you');
    await expect(winBack).toContainText('Your message, sent as written');

    await testInfo.attach('automations', {
        body: await page.screenshot({ fullPage: true }),
        contentType: 'image/png',
    });

    // Back to ours.
    await page.locator('[data-test="automation-win_back-edit"]').click();
    await editor.locator('[data-test="automation-use-ours"]').click();
    // The save and the page's reload run in the request: give it time.
    await expect(editor).toBeHidden({ timeout: 20_000 });
    await expect(winBack).toContainText('We miss you at Café Hafa');
    await expect(winBack).toContainText(
        "Our message, in each customer's language",
    );
});
