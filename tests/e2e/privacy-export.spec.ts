import { readFile } from 'node:fs/promises';
import { expect, test } from '@playwright/test';
import { fillSignUp } from './helpers';

/**
 * Download my data (CHW-58, C12) on a phone: confirm it's you from the
 * Privacy page, come back to it, then the file downloads and the page stays
 * (a plain link, which the service worker leaves to the network). A new
 * account each run, so it shares no sign-in limit with the demo customer.
 */
test.skip(
    ({ isMobile }) => !isMobile,
    'The customer screens are phone screens.',
);

test('confirms it is you, then downloads the data as JSON', async ({
    page,
}) => {
    await page.goto('/register');
    await fillSignUp(page);
    await page.waitForURL((url) => !url.pathname.startsWith('/register'));

    await page.goto('/settings/privacy');
    await page.locator('[data-test="confirm-download"]').click();
    await expect(page).toHaveURL(/\/user\/confirm-password$/);
    await page.locator('#password').fill('a-long-test-password-1');
    await page.locator('[data-test="confirm-password-button"]').click();
    await expect(page).toHaveURL(/\/settings\/privacy$/);

    const [download] = await Promise.all([
        page.waitForEvent('download'),
        page.locator('[data-test="download-data"]').click(),
    ]);
    const data = JSON.parse(await readFile(await download.path(), 'utf8'));

    expect(download.suggestedFilename()).toMatch(
        /^punchcard-data-\d{4}-\d{2}-\d{2}\.json$/,
    );
    expect(data).toMatchObject({
        format_version: 1,
        account: { name: 'Salma Tapper' },
        cards: [],
    });
    await expect(page).toHaveURL(/\/settings\/privacy$/);
});
