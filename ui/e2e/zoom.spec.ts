import { expect, test, type Page } from '@playwright/test';
import { closeSmallScreenNote, openViewer } from './helpers';

const zoom = (page: Page) => page.evaluate(() => Number(/scale\(([\d.]+)\)/.exec(document.querySelector<HTMLElement>('.react-flow__viewport')!.style.transform)?.[1]));
const offset = (page: Page) => page.evaluate(() => document.querySelector<HTMLElement>('.react-flow__viewport')!.style.transform.split('scale')[0]);

// Waits until the opening animation has stopped moving the diagram, so a click lands where it was aimed.
const settled = async (page: Page) => {
    let last = '';
    await expect
        .poll(async () => {
            const now = await page.evaluate(() => document.querySelector<HTMLElement>('.react-flow__viewport')!.style.transform);
            const same = now === last;
            last = now;
            return same;
        }, { intervals: [250] })
        .toBe(true);
};

// A table card on screen, clear of the bars and the panel: its name and a point on its header.
const cardOnScreen = (page: Page) =>
    page.evaluate(() => {
        const pane = document.querySelector('.react-flow__pane')!.getBoundingClientRect();
        for (const el of document.querySelectorAll<HTMLElement>('.react-flow__node-table')) {
            const r = el.getBoundingClientRect();
            const x = r.left + 20;
            const y = r.top + 6;
            if (x > pane.left + 10 && x < pane.right - 10 && y > pane.top + 60 && y < pane.bottom - 120) {
                return { name: el.querySelector('.table-node__name')!.textContent!, x, y };
            }
        }
        return null;
    });

test('shows a picture with placeholder columns at 40% and below', async ({ page }) => {
    await openViewer(page, { hash: 'at=0,0,0.4' });
    await closeSmallScreenNote(page);

    await expect(page.locator('.canvas.is-picture')).toHaveCount(1);
    await expect(page.locator('.diagram-picture__canvas')).toHaveCount(1);
});

test('shows the cards above 40%', async ({ page }) => {
    await openViewer(page, { hash: 'at=0,0,0.45' });
    await closeSmallScreenNote(page);
    await page.waitForTimeout(800);

    await expect(page.locator('.canvas.is-picture')).toHaveCount(0);
});

test('picks a table by clicking it on the picture, and keeps it live on top', async ({ page }) => {
    await openViewer(page, { hash: 'at=0,0,0.3' });
    await closeSmallScreenNote(page);
    await expect(page.locator('.canvas.is-picture')).toHaveCount(1);

    const card = (await cardOnScreen(page))!;
    await page.mouse.click(card.x, card.y);

    await expect(page.locator('.details__head h2').first()).toHaveText(card.name);
    await expect(page.locator('.react-flow__node.selected')).toBeVisible();
});

test.describe('pointer', () => {
    test.skip(({ isMobile }) => isMobile, 'A phone pans with a finger and zooms with a pinch.');

    test('scrolls to move the diagram and zooms with Ctrl or Cmd held', async ({ page }) => {
        await openViewer(page, { hash: 'at=0,0,1' });
        await page.mouse.move(300, 300);

        const before = await offset(page);
        await page.mouse.wheel(0, 200);
        await expect.poll(() => offset(page)).not.toBe(before);
        expect(await zoom(page)).toBe(1);

        await page.keyboard.down('Control');
        await page.mouse.wheel(0, -100);
        await page.keyboard.up('Control');
        await expect.poll(() => zoom(page)).toBeCloseTo(1.2, 2);

        await page.keyboard.down('Meta');
        await page.mouse.wheel(0, 100);
        await page.keyboard.up('Meta');
        await expect.poll(() => zoom(page)).toBeCloseTo(1, 2);
    });

    test('still picks a table with a click in pan mode', async ({ page }) => {
        await openViewer(page, { hash: 'at=0,0,1' });
        await page.getByRole('button', { name: /Pan mode/ }).click();

        const card = (await cardOnScreen(page))!;
        await page.mouse.click(card.x, card.y);

        await expect(page.locator('.details__head h2').first()).toHaveText(card.name);
    });

    test('in pan mode, picks a column and a line with a click, and a drag over a card moves the diagram, not the card', async ({ page }) => {
        await openViewer(page, { hash: 'table=testing:posts&focus=1' });
        await settled(page);
        await page.getByRole('button', { name: /Pan mode/ }).click();

        await page.locator('.edge-label').first().click();
        await expect(page.locator('.details__head h2').first()).toContainText('→');

        const row = page.locator('.react-flow__node-table').filter({ hasText: 'posts' }).first().locator('.table-node__columns li').filter({ hasText: 'title' });
        await row.click();
        await expect(page.locator('.details__head h2').first()).toHaveText('posts.title');

        const card = page.locator('.react-flow__node-table').filter({ hasText: 'posts' }).first();
        const box = (await card.boundingBox())!;
        const cardAt = await card.evaluate((el) => el.style.transform);
        const view = await offset(page);
        await page.mouse.move(box.x + 60, box.y + 12);
        await page.mouse.down();
        await page.mouse.move(box.x + 160, box.y + 80, { steps: 8 });
        await page.mouse.up();
        await expect.poll(() => offset(page)).not.toBe(view);
        expect(await card.evaluate((el) => el.style.transform)).toBe(cardAt);
    });

    test('outside pan mode, a drag on a card moves the card', async ({ page }) => {
        await openViewer(page, { hash: 'table=testing:posts&focus=1' });
        const card = page.locator('.react-flow__node-table').filter({ hasText: 'posts' }).first();
        const box = (await card.boundingBox())!;
        const cardAt = await card.evaluate((el) => el.style.transform);
        await page.mouse.move(box.x + 60, box.y + 12);
        await page.mouse.down();
        await page.mouse.move(box.x + 160, box.y + 80, { steps: 8 });
        await page.mouse.up();
        await expect.poll(() => card.evaluate((el) => el.style.transform)).not.toBe(cardAt);
    });

    test('shows the hand over the diagram in pan mode, and the pointer over what a click picks otherwise', async ({ page }) => {
        await openViewer(page, { hash: 'table=testing:posts&focus=1' });
        const cursorOf = (selector: string) => page.locator(selector).first().evaluate((el) => getComputedStyle(el).cursor);

        expect(await cursorOf('.table-node__columns li')).toBe('pointer');
        expect(await cursorOf('.edge-label')).toBe('pointer');
        expect(await cursorOf('.react-flow__node-table')).toBe('grab');

        await page.getByRole('button', { name: /Pan mode/ }).click();
        expect(await cursorOf('.table-node__columns li')).toBe('grab');
        expect(await cursorOf('.edge-label')).toBe('grab');
        expect(await cursorOf('.react-flow__node-table')).toBe('grab');
    });

    test('double-clicking a table on the picture focuses on it', async ({ page }) => {
        await openViewer(page, { hash: 'at=0,0,0.3' });
        await expect(page.locator('.canvas.is-picture')).toHaveCount(1);
        const card = (await cardOnScreen(page))!;

        await page.mouse.dblclick(card.x, card.y);

        await expect(page.getByRole('group', { name: 'Focus mode' })).toContainText(card.name);
    });
});
