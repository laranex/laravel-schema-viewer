import { expect, test } from '@playwright/test';
import { openViewer } from './helpers';

test.skip(({ isMobile }) => isMobile, 'The side panel is a bottom sheet on a phone; phone.spec.ts covers it.');

test('filters Browse and shows the picked table in Details', async ({ page }) => {
    await openViewer(page);
    await page.getByRole('tab', { name: /Browse/ }).click();
    await page.fill('#table-filter', 'posts');
    await page.locator('.table-list__row').filter({ hasText: /^posts/ }).first().click();

    await expect(page.getByRole('tab', { name: /Details/ })).toHaveAttribute('aria-selected', 'true');
    await expect(page.locator('.details__head h2')).toHaveText('posts');
});

test('counts the hints and opens the rules that ignore one', async ({ page, browserName, context }) => {
    await openViewer(page);
    await page.keyboard.press('h');

    const count = Number(await page.getByRole('tab', { name: /Hints/ }).locator('.count').innerText());
    expect(count).toBeGreaterThan(0);
    await expect(page.locator('.hint')).toHaveCount(count);

    await page.locator('.hint').first().getByRole('button', { name: 'Ignore…' }).click();
    const dialog = page.getByRole('dialog', { name: 'Ignore this hint' });
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('.ignore__rules code').first()).toContainText("'kind' =>");

    // WebKit's automation does not grant clipboard access.
    if (browserName === 'chromium') {
        await context.grantPermissions(['clipboard-read', 'clipboard-write']);
        await dialog.getByRole('button', { name: /^Copy the rule/ }).first().click();
        await expect(dialog.getByRole('button', { name: /^Copy the rule/ }).first()).toHaveText('Copied');
        expect(await page.evaluate(() => navigator.clipboard.readText())).toContain("'kind' =>");
    }

    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
});
