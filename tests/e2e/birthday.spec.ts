import { expect, test } from '@playwright/test';
import { fillSignUp } from './helpers';

/**
 * The optional birthday on C12 Profile (CHW-46, design 17a), on a phone:
 * a new customer picks a day and a month, saves, finds them again, then
 * removes them. A new account each run, so it shares no sign-in limit with
 * the demo customer.
 */
test.skip(
    ({ isMobile }) => !isMobile,
    'The customer screens are phone screens.',
);

test('sets a birthday on the profile, finds it again, then removes it', async ({
    page,
}, testInfo) => {
    await page.goto('/register');
    await fillSignUp(page);
    await page.waitForURL((url) => !url.pathname.startsWith('/register'));

    await page.goto('/settings/profile');
    // One group a screen reader names "Birthday, optional", its hint read with each select.
    const birthday = page.getByRole('group', { name: 'Birthday Optional' });
    await expect(birthday.locator('#birthday_day')).toHaveAccessibleDescription(
        /treat on your birthday/,
    );
    await birthday.locator('#birthday_day').click();
    await page.getByRole('option', { name: '14', exact: true }).click();
    await birthday.locator('[data-test="birthday-month"]').click();
    await page.getByRole('option', { name: 'March' }).click();
    await testInfo.attach('birthday', {
        body: await page.screenshot({ fullPage: true }),
        contentType: 'image/png',
    });
    await page.locator('[data-test="update-profile-button"]').click();
    await expect(page.getByText('Profile updated.')).toBeVisible();

    await page.reload();
    await expect(birthday.locator('#birthday_day')).toHaveText('14');
    await expect(birthday.locator('[data-test="birthday-month"]')).toHaveText(
        'March',
    );

    await birthday.locator('[data-test="remove-birthday"]').click();
    await page.locator('[data-test="update-profile-button"]').click();
    await expect(page.getByText('Profile updated.')).toBeVisible();
    await page.reload();
    await expect(birthday.locator('#birthday_day')).toHaveText('Day');
    await expect(birthday.locator('[data-test="remove-birthday"]')).toHaveCount(
        0,
    );
});
