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

test('quick taps on the reminder wait add up, saved once', async ({ page }) => {
    await page.goto('/business/automations');

    const reminder = page.locator('[data-test="automation-reward_reminder"]');
    const value = page.locator(
        '[data-test="automation-reward_reminder-value"]',
    );
    const days = async () =>
        Number.parseInt((await value.textContent()) ?? '', 10);
    // Away from the limits (1 to 60), so three steps either way fit.
    const before = await days();
    const [step, back] =
        before > 57 ? ['One fewer', 'One more'] : ['One more', 'One fewer'];
    const after = before > 57 ? before - 3 : before + 3;

    // Three taps before the first save goes: one save of all three, not three saves of the first.
    for (let tap = 0; tap < 3; tap++) {
        await reminder.getByRole('button', { name: step }).click();
    }
    await expect(value).toHaveText(`${after} days`);
    await expect(page.getByText('Saved.')).toBeVisible({ timeout: 20_000 });

    await page.reload();
    await expect(value).toHaveText(`${after} days`, { timeout: 20_000 });

    for (let tap = 0; tap < 3; tap++) {
        await reminder.getByRole('button', { name: back }).click();
    }
    await expect(value).toHaveText(`${before} days`);
    await expect(page.getByText('Saved.').first()).toBeVisible({
        timeout: 20_000,
    });
    await page.reload();
    await expect(value).toHaveText(`${before} days`, { timeout: 20_000 });
});
