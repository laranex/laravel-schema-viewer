import { expect, test } from '@playwright/test';
import { closeSmallScreenNote, openViewer } from './helpers';

test('draws cards as bars and leaves labels out when zoomed out too far to read them', async ({ page }) => {
    await openViewer(page, { hash: 'at=0,0,0.3' });
    await closeSmallScreenNote(page);

    await expect.poll(() => page.locator('.table-node.is-low').count()).toBeGreaterThan(0);
    await expect(page.locator('.table-node__columns')).toHaveCount(0);
    await expect(page.locator('.edge-label')).toHaveCount(0);
});

test('labels the picked table\'s lines even when zoomed out', async ({ page }) => {
    await openViewer(page, { hash: 'table=testing:posts&at=0,0,0.3' });
    await closeSmallScreenNote(page);

    await expect.poll(() => page.locator('.table-node.is-low').count()).toBeGreaterThan(0);
    await expect.poll(() => page.locator('.edge-label').count()).toBeGreaterThan(0);
});

test('draws full cards and every label at a readable zoom', async ({ page }) => {
    await openViewer(page, { hash: 'at=0,0,1' });
    await closeSmallScreenNote(page);

    await expect.poll(() => page.locator('.table-node__columns').count()).toBeGreaterThan(0);
    await expect(page.locator('.table-node.is-low')).toHaveCount(0);
});
