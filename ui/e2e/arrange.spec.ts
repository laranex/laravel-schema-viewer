import { expect, test, type Page } from '@playwright/test';
import { openViewer } from './helpers';

test.skip(({ isMobile }) => isMobile, 'A phone keeps the layout that suits its screen; Arrange is not in its bar.');

// Where each rendered card is, by table; only cards on screen are rendered, so comparisons use the tables both have.
const positions = (page: Page) => page.locator('.react-flow__node-table').evaluateAll((els) => Object.fromEntries(els.map((el) => [el.dataset.id, (el as HTMLElement).style.transform])));
const same = (a: Record<string, string>, b: Record<string, string>) => Object.keys(a).filter((id) => id in b).every((id) => a[id] === b[id]);

test('arranges the tables left to right, as a snowflake or compactly', async ({ page }) => {
    await openViewer(page);
    const leftRight = await positions(page);

    await page.getByRole('button', { name: 'Arrange tables automatically' }).click();
    await expect(page.getByRole('menuitem')).toHaveText([/^Left-right/, /^Snowflake/, /^Compact/]);
    await page.getByRole('menuitem', { name: /^Compact/ }).click();
    await expect.poll(async () => same(await positions(page), leftRight)).toBe(false);
    const compact = await positions(page);

    await page.getByRole('button', { name: 'Arrange tables automatically' }).click();
    await page.getByRole('menuitem', { name: /^Snowflake/ }).click();
    await expect.poll(async () => same(await positions(page), compact)).toBe(false);

    await page.getByRole('button', { name: 'Arrange tables automatically' }).click();
    await page.getByRole('menuitem', { name: /^Left-right/ }).click();
    await expect.poll(async () => same(await positions(page), leftRight)).toBe(true);
});

test('has no keyboard shortcut', async ({ page }) => {
    await openViewer(page);
    await page.locator('.react-flow__pane').click({ position: { x: 5, y: 5 } });

    await page.keyboard.press('Shift+A');
    await page.waitForTimeout(500);

    // Arranging always says so; nothing does.
    await expect(page.locator('.toast', { hasText: 'arranged' })).toHaveCount(0);
});
