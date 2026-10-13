import { expect, test } from '@playwright/test';
import { openViewer } from './helpers';

test.skip(({ isMobile }) => isMobile, 'The phone reaches the same dialog from More actions; phone.spec.ts opens it.');

for (const [label, file] of [
    ['Mermaid', 'schema-testing.mmd'],
    ['DBML', 'schema-testing.dbml'],
    ['Markdown', 'schema-testing.md'],
    ['JSON', 'schema-testing.json'],
]) {
    test(`downloads ${label} as ${file}`, async ({ page }) => {
        await openViewer(page);
        await page.getByRole('button', { name: 'Download', exact: true }).click();
        const dialog = page.getByRole('dialog', { name: 'Download' });

        const [download] = await Promise.all([page.waitForEvent('download'), dialog.getByRole('button', { name: new RegExp(`^${label}`) }).click()]);

        expect(download.suggestedFilename()).toBe(file);
    });
}

test('downloads this page as HTML that opens from disk', async ({ page, browser, browserName }, testInfo) => {
    await openViewer(page);
    await page.getByRole('button', { name: 'Download', exact: true }).click();

    const [download] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: /^This page as HTML/ }).click()]);
    expect(download.suggestedFilename()).toBe('schema-viewer.html');

    // WebKit refuses module scripts from blob URLs on file:// pages.
    test.skip(browserName === 'webkit', 'WebKit does not run the page from file://.');

    const path = testInfo.outputPath('schema-viewer.html');
    await download.saveAs(path);
    const offline = await browser.newPage();
    await offline.goto(`file://${path}`);
    await expect(offline.locator('.react-flow__node-table').first()).toBeVisible();
    await offline.close();
});
