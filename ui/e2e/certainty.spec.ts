import { expect, test } from '@playwright/test';
import { openViewer } from './helpers';

test.skip(({ isMobile }) => isMobile, 'The Hints tab is the same on a phone, inside the sheet.');

test('marks a likely hint and filters the hints by certainty', async ({ page }) => {
    await openViewer(page, { fixture: 'likely' });
    await page.keyboard.press('h');
    const all = await page.locator('.hint').count();

    await expect(page.locator('.hint .badge--likely')).toHaveCount(1);
    await page.getByRole('radio', { name: /^Likely/ }).click();
    await expect(page.locator('.hint')).toHaveCount(1);
    await expect(page.locator('.hint')).toContainText('Index covered by another');
    await page.getByRole('radio', { name: /^Certain/ }).click();
    await expect(page.locator('.hint')).toHaveCount(all - 1);
    await page.getByRole('radio', { name: /^All/ }).click();
    await expect(page.locator('.hint')).toHaveCount(all);
});

test('shows no certainty filter when every hint is certain', async ({ page }) => {
    await openViewer(page);
    await page.keyboard.press('h');

    await expect(page.locator('.hint').first()).toBeVisible();
    await expect(page.getByRole('radiogroup', { name: 'Show hints' })).toHaveCount(0);
    await expect(page.locator('.badge--likely')).toHaveCount(0);
});
