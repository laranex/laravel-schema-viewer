import { expect, test } from '@playwright/test';
import { openViewer } from './helpers';

test.skip(({ isMobile }) => !isMobile, 'Only a phone shows the sheet and the small-screen note.');

test('says once that a larger screen is easier', async ({ page }) => {
    await openViewer(page);
    const note = page.locator('.screen-note');
    await expect(note).toContainText('Easier on a larger screen.');

    await note.getByRole('button', { name: 'Close' }).click();
    await expect(note).toBeHidden();
    await page.reload();
    await expect(page.locator('.react-flow__node-table').first()).toBeVisible();
    await expect(note).toBeHidden();
});

test('shows a table in the bottom sheet and the actions in More actions', async ({ page }) => {
    await openViewer(page, { hash: 'table=testing:posts' });
    await page.locator('.screen-note').getByRole('button', { name: 'Close' }).click();

    const bar = page.locator('.sheet-bar');
    await expect(bar).toContainText('posts');
    await bar.getByRole('button', { name: 'Details' }).click();
    await expect(page.locator('.details__head h2')).toHaveText('posts');
    await bar.getByRole('button', { name: 'Close' }).click();

    await page.getByRole('button', { name: 'More actions' }).click();
    await expect(page.getByRole('menuitem', { name: /Download/ })).toBeVisible();
    await page.getByRole('menuitem', { name: /Download/ }).click();
    await expect(page.getByRole('dialog', { name: 'Download' })).toBeVisible();
});
