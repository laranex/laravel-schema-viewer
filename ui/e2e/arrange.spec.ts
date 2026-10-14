import { expect, test, type Page } from '@playwright/test';
import { openViewer } from './helpers';

test.skip(({ isMobile }) => isMobile, 'A phone keeps the layout that suits its screen; Arrange is not in its bar.');

const positions = (page: Page) => page.locator('.react-flow__node-table').evaluateAll((els) => els.map((el) => (el as HTMLElement).style.transform).join('|'));

test('arranges the tables left to right, as a snowflake or compactly, and remembers the choice', async ({ page }) => {
    await openViewer(page);
    const leftRight = await positions(page);

    await page.getByRole('button', { name: 'Arrange tables automatically' }).click();
    await expect(page.getByRole('menuitem')).toHaveText([/^Left-right/, /^Snowflake/, /^Compact/]);
    await expect(page.getByRole('menuitem', { name: /^Left-right/ })).toContainText('Shift A');

    await page.getByRole('menuitem', { name: /^Compact/ }).click();
    await expect.poll(() => positions(page)).not.toBe(leftRight);
    const compact = await positions(page);

    // Shift A arranges the chosen way again, and the choice survives a reload.
    await page.keyboard.press('Shift+A');
    await expect.poll(() => positions(page)).toBe(compact);
    await page.reload();
    await page.getByRole('button', { name: 'Arrange tables automatically' }).click();
    await expect(page.getByRole('menuitem', { name: /^Compact/ })).toContainText('Shift A');

    await page.getByRole('menuitem', { name: /^Snowflake/ }).click();
    await expect.poll(() => positions(page)).not.toBe(compact);
});
