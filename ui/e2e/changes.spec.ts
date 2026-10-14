import { expect, test } from '@playwright/test';
import { closeSmallScreenNote, openViewer } from './helpers';

test('lists what changed since the previous build and opens what is still there', async ({ page, isMobile }) => {
    await openViewer(page, { fixture: 'changes' });
    await closeSmallScreenNote(page);
    await page.keyboard.press('w');

    const tab = page.getByRole('tab', { name: /Changes/ });
    await expect(tab).toHaveAttribute('aria-selected', 'true');
    await expect(tab.locator('.count')).toHaveText('5');
    await expect(page.locator('.changes__head')).toContainText('Changed by the last build that found a difference');

    // Removed things are struck through and lead nowhere.
    await expect(page.locator('.change--removed del')).toHaveCount(2);
    await expect(page.locator('.change--removed button')).toHaveCount(0);

    if (!isMobile) {
        await expect(page.locator('.table-node__change--new')).toHaveCount(1);
        await expect(page.locator('.table-node__change--changed')).toHaveCount(1);
    }

    // A column the snapshot no longer has opens its table.
    await page.getByRole('button', { name: /^Column subtitle added/ }).click();
    await expect(page.locator('.details__head h2')).toHaveText('posts');

    await page.keyboard.press('w');
    await page.getByRole('button', { name: /^Column title changed/ }).click();
    await expect(page.locator('.details__head h2')).toHaveText('posts.title');
});

test('says there is nothing to compare before a second build', async ({ page }) => {
    await openViewer(page);
    await closeSmallScreenNote(page);
    await page.keyboard.press('w');

    await expect(page.getByText('Nothing to compare yet.')).toBeVisible();
});
