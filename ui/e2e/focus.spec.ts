import { expect, test } from '@playwright/test';
import { openViewer } from './helpers';

test.skip(({ isMobile }) => isMobile, 'Double-click focus is a pointer gesture; the phone opens the same focus from Details.');

test('focuses on a table by double-click, changes the links away and leaves with Esc', async ({ page }) => {
    await openViewer(page);
    const all = await page.locator('.react-flow__node-table').count();

    await page.locator('.react-flow__node-table').filter({ hasText: 'roles' }).first().locator('.table-node__head, header').first().dblclick();
    const bar = page.getByRole('group', { name: 'Focus mode' });
    await expect(bar).toBeVisible();

    for (const links of ['1 link', '2 links', '3 links']) {
        await bar.getByRole('radio', { name: links }).click();
        await expect(bar.getByRole('radio', { name: links })).toHaveAttribute('aria-checked', 'true');
    }

    await bar.getByRole('radio', { name: '1 link' }).click();
    await expect.poll(() => page.locator('.react-flow__node-table').count()).toBeLessThan(all);

    await page.keyboard.press('Escape');
    await expect(bar).toBeHidden();
});

test('opens a deep link in focus mode', async ({ page }) => {
    await openViewer(page, { hash: 'table=testing:posts&focus=1' });

    await expect(page.getByRole('group', { name: 'Focus mode' })).toContainText('posts');
    await expect(page.locator('.details__head h2')).toHaveText('posts');
});
