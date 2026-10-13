import { readFile } from 'node:fs/promises';
import { expect, test, type Page } from '@playwright/test';
import { openViewer } from './helpers';

test.skip(({ isMobile }) => isMobile, 'The phone reaches the same dialog from More actions.');

async function downloadImage(page: Page, format: 'SVG' | 'PNG', area?: string) {
    await page.getByRole('button', { name: 'Download', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: 'Download' });
    if (area) {
        await dialog.getByRole('radio', { name: area }).click();
    }
    const [download] = await Promise.all([page.waitForEvent('download'), dialog.getByRole('button', { name: new RegExp(`^${format}`) }).click()]);
    return download;
}

test('downloads the diagram as an SVG with every table shown', async ({ page }) => {
    await openViewer(page);
    const download = await downloadImage(page, 'SVG');
    const svg = await readFile((await download.path())!, 'utf8');

    expect(download.suggestedFilename()).toBe('schema-testing.svg');
    expect(svg).toMatch(/^<svg xmlns="http:\/\/www\.w3\.org\/2000\/svg"/);
    expect(svg).toContain('@font-face');
    for (const table of ['posts', 'users', 'comments', 'taggables']) {
        expect(svg).toContain(`>${table}</text>`);
    }
    expect(svg).not.toContain('var(--');
});

test('downloads the diagram as a PNG', async ({ page }) => {
    await openViewer(page);
    const download = await downloadImage(page, 'PNG');
    const png = await readFile((await download.path())!);

    expect(download.suggestedFilename()).toBe('schema-testing.png');
    expect(png.subarray(1, 4).toString()).toBe('PNG');
    // Twice the size of the diagram at least: width is bytes 16 to 19.
    expect(png.readUInt32BE(16)).toBeGreaterThan(1000);
});

test('downloads only what is on screen when asked', async ({ page }) => {
    await openViewer(page, { hash: 'table=testing:posts&focus=1' });
    const whole = await readFile((await (await downloadImage(page, 'SVG')).path())!, 'utf8');
    await page.keyboard.press('Escape');
    const screen = await readFile((await (await downloadImage(page, 'SVG', "What's on screen")).path())!, 'utf8');

    // Focus mode leaves out the tables two links away.
    expect(whole).not.toContain('>audit_logs</text>');
    expect(screen).toMatch(/viewBox="[-\d. ]+"/);
    expect(screen).not.toBe(whole);
});
