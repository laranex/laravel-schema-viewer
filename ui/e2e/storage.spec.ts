import { expect, test } from '@playwright/test';
import { closeSmallScreenNote, openViewer } from './helpers';

test.skip(({ isMobile }) => isMobile, 'The settings it changes sit in the floating bar, which a phone moves into a menu.');

test('remembers only the theme, and clears what earlier versions kept', async ({ page }) => {
    // What an earlier version left behind, table names included.
    await page.addInitScript(() => {
        if (!sessionStorage.getItem('seeded')) {
            sessionStorage.setItem('seeded', '1');
            localStorage.setItem('schema-viewer.folds', JSON.stringify({ 'testing.posts': 'keys' }));
            localStorage.setItem('schema-viewer.grid', 'false');
            localStorage.setItem('schema-viewer.panel-width', '500');
        }
    });
    await openViewer(page);
    await closeSmallScreenNote(page);

    // Change every remembered-looking setting: theme, columns, grid, arrangement.
    await page.getByRole('button', { name: /dark mode|light mode/i }).first().click();
    await page.keyboard.press('c');
    await page.getByRole('button', { name: 'Arrange tables automatically' }).click();
    await page.getByRole('menuitem', { name: /^Compact/ }).click();

    const keys = await page.evaluate(() => Object.keys(localStorage).filter((k) => k.startsWith('schema-viewer.')));
    expect(keys).toEqual(['schema-viewer.theme']);
});
