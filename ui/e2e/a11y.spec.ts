import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { openViewer } from './helpers';

test.skip(({ browserName, isMobile }) => browserName !== 'chromium' || isMobile, 'axe runs once, in desktop Chromium.');

// Each rule left out, and why.
const DISABLED: Record<string, string> = {};

async function audit(page: Page, include?: string) {
    // Colors are measured as painted: let dialogs finish fading in first.
    await page.waitForFunction(() => document.getAnimations().every((a) => a.playState !== 'running'));
    let builder = new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).disableRules(Object.keys(DISABLED));
    if (include) {
        builder = builder.include(include);
    }
    const results = await builder.analyze();
    return results.violations.map((v) => `${v.id}: ${v.help} (${v.nodes.map((n) => n.target.join(' ')).slice(0, 3).join(', ')})`);
}

for (const theme of ['light', 'dark'] as const) {
    test.describe(`${theme} mode`, () => {
        test.beforeEach(async ({ page }) => {
            await page.addInitScript((t) => localStorage.setItem('schema-viewer.theme', JSON.stringify(t)), theme);
            await openViewer(page);
            await expect(page.locator('html')).toHaveAttribute('data-theme', theme);
        });

        test('the main view', async ({ page }) => {
            expect(await audit(page)).toEqual([]);
        });

        test('the Download dialog', async ({ page }) => {
            await page.getByRole('button', { name: 'Download', exact: true }).click();
            await expect(page.getByRole('dialog', { name: 'Download' })).toBeVisible();
            expect(await audit(page, '[role="dialog"]')).toEqual([]);
        });

        test('the Ignore dialog', async ({ page }) => {
            await page.keyboard.press('h');
            await page.locator('.hint').first().getByRole('button', { name: 'Ignore…' }).click();
            await expect(page.getByRole('dialog', { name: 'Ignore this hint' })).toBeVisible();
            expect(await audit(page, '[role="dialog"]')).toEqual([]);
        });

        test('the keyboard shortcuts', async ({ page }) => {
            await page.keyboard.press('s');
            await expect(page.getByRole('dialog', { name: 'Keyboard shortcuts' })).toBeVisible();
            expect(await audit(page, '[role="dialog"]')).toEqual([]);
        });

        test('the tour', async ({ page }) => {
            await page.getByRole('button', { name: 'Take the tour' }).click();
            await expect(page.locator('.tour__card')).toBeVisible();
            expect(await audit(page, '.tour__card')).toEqual([]);
        });
    });
}
