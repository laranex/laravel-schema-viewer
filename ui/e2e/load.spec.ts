import { expect, test } from '@playwright/test';
import { openViewer, watchErrors } from './helpers';

test('draws every table of the snapshot without errors', async ({ page }) => {
    const errors = watchErrors(page);
    await openViewer(page);

    await expect(page.locator('.react-flow__node-table').filter({ hasText: 'posts' }).first()).toBeVisible();
    expect(await page.locator('.react-flow__node-table').count()).toBeGreaterThanOrEqual(5);
    expect(errors).toEqual([]);
});

test('says how to build the snapshot when there is none', async ({ page }) => {
    const errors = watchErrors(page);
    await page.goto('/?fixture=missing');

    await expect(page.getByRole('heading', { name: 'No schema yet' })).toBeVisible();
    await expect(page.getByText('php artisan schema-viewer:build').first()).toBeVisible();
    expect(errors).toEqual([]);
});

test('opens a schema of 250 tables', async ({ page }) => {
    const errors = watchErrors(page);
    await openViewer(page, { fixture: 'large' });

    expect(errors).toEqual([]);
});
