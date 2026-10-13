import { expect, test } from '@playwright/test';
import { closeSmallScreenNote, openViewer } from './helpers';

test('takes the tour with ?, steps on and ends with Esc', async ({ page }) => {
    await openViewer(page);
    await closeSmallScreenNote(page);
    await page.getByRole('button', { name: 'Take the tour' }).click();

    const card = page.locator('.tour__card');
    await expect(card.getByRole('heading', { name: 'Welcome to Schema Viewer' })).toBeVisible();
    await card.getByRole('button', { name: 'Next' }).click();
    await expect(card.getByRole('heading', { name: 'The diagram' })).toBeVisible();

    await page.keyboard.press('Escape');
    await expect(card).toBeHidden();

    await page.keyboard.press('?');
    await expect(card.getByRole('heading', { name: 'Welcome to Schema Viewer' })).toBeVisible();
});

test('shows every step of the tour on screen', async ({ page }) => {
    await openViewer(page);
    await closeSmallScreenNote(page);
    await page.getByRole('button', { name: 'Take the tour' }).click();
    const card = page.locator('.tour__card');
    const viewport = page.viewportSize()!;

    for (let step = 0; step < 30; step++) {
        await expect(card).toBeVisible();
        // The card glides to each step; wait for it to settle inside the screen.
        await expect
            .poll(async () => {
                const box = await card.boundingBox();
                return !!box && box.x >= 0 && box.y >= 0 && box.x + box.width <= viewport.width + 1 && box.y + box.height <= viewport.height + 1;
            }, { message: await card.locator('h2').innerText() })
            .toBe(true);
        const next = card.getByRole('button', { name: /^(Next|Done)$/ });
        const done = (await next.innerText()) === 'Done';
        await next.click();
        if (done) {
            break;
        }
    }

    await expect(card).toBeHidden();
});
