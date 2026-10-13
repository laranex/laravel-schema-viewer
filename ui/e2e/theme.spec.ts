import { expect, test } from '@playwright/test';
import { closeSmallScreenNote, openViewer } from './helpers';

test('switches to dark mode and keeps it after a reload', async ({ page, isMobile }) => {
    await openViewer(page);
    await closeSmallScreenNote(page);

    if (isMobile) {
        await page.getByRole('button', { name: 'More actions' }).click();
        await page.getByRole('menuitem', { name: 'Dark mode' }).click();
    } else {
        await page.getByRole('button', { name: 'Switch to dark mode' }).click();
    }
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');

    await page.reload();
    await expect(page.locator('.react-flow__node-table').first()).toBeVisible();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
});
