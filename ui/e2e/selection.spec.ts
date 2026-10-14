import { expect, test, type Page } from '@playwright/test';
import { openViewer } from './helpers';

test.skip(({ isMobile }) => isMobile, 'A phone drags to move the diagram and picks one table at a time.');

const view = (page: Page) => page.evaluate(() => document.querySelector<HTMLElement>('.react-flow__viewport')!.style.transform);
const selected = (page: Page) => page.locator('.react-flow__node.selected').evaluateAll((els) => els.map((el) => el.querySelector('.table-node__name')?.textContent).sort());
const positions = (page: Page) => page.locator('.react-flow__node-table').evaluateAll((els) => Object.fromEntries(els.map((el) => [el.dataset.id, (el as HTMLElement).style.transform])));

// Waits until the opening animation has stopped moving the diagram, so a click lands where it was aimed.
const settled = async (page: Page) => {
    let last = '';
    await expect
        .poll(async () => {
            const now = await view(page);
            const same = now === last;
            last = now;
            return same;
        }, { intervals: [250] })
        .toBe(true);
};

// A point on the header of a table's card.
const header = async (page: Page, table: string) => {
    const box = (await page.locator('.react-flow__node-table').filter({ has: page.locator('.table-node__name', { hasText: new RegExp(`^${table}$`) }) }).first().boundingBox())!;
    return { x: box.x + 40, y: box.y + 10 };
};

// A point on the empty background, clear of the cards, the panel and the bars.
const background = (page: Page) =>
    page.evaluate(() => {
        const pane = document.querySelector('.react-flow__pane')!.getBoundingClientRect();
        for (let y = pane.top + 70; y < pane.bottom - 150; y += 7) {
            for (let x = pane.left + 20; x < pane.right - 60; x += 7) {
                if (document.elementFromPoint(x, y)?.classList.contains('react-flow__pane')) {
                    return { x, y };
                }
            }
        }
        return null;
    });

test('Shift+click picks several tables, a drag moves them together, Esc clears them', async ({ page }) => {
    await openViewer(page, { hash: 'table=testing:posts&focus=1' });
    // Fit the focused tables, so every card is wholly on screen.
    await page.keyboard.press('f');
    await settled(page);

    for (const table of ['users', 'categories']) {
        const at = await header(page, table);
        await page.keyboard.down('Shift');
        await page.mouse.click(at.x, at.y);
        await page.keyboard.up('Shift');
    }
    await expect.poll(() => selected(page)).toEqual(['categories', 'posts', 'users']);
    await expect(page.locator('.details__head h2').first()).toHaveText('3 tables selected');

    const before = await positions(page);
    const at = await header(page, 'users');
    await page.mouse.move(at.x, at.y);
    await page.mouse.down();
    await page.mouse.move(at.x + 90, at.y + 50, { steps: 8 });
    await page.mouse.up();
    const after = await positions(page);
    // Only cards on screen are rendered, so compare the tables both readings have.
    expect(Object.keys(before).filter((id) => id in after && before[id] !== after[id]).sort()).toEqual(['testing.categories', 'testing.posts', 'testing.users']);

    const again = await header(page, 'categories');
    await page.keyboard.down('Shift');
    await page.mouse.click(again.x, again.y);
    await page.keyboard.up('Shift');
    await expect.poll(() => selected(page)).toEqual(['posts', 'users']);

    await page.keyboard.press('Escape');
    await expect.poll(() => selected(page)).toEqual([]);
});

test('a drag on the background draws a box that picks tables and does not move the diagram', async ({ page }) => {
    await openViewer(page, { hash: 'at=0,0,0.6' });
    await settled(page);
    const start = (await background(page))!;
    const before = await view(page);

    // Across most of the diagram, to the far corner of the background.
    const end = await page.evaluate(() => {
        const pane = document.querySelector('.react-flow__pane')!.getBoundingClientRect();
        return { x: pane.right - 80, y: pane.bottom - 160 };
    });
    await page.mouse.move(start.x, start.y);
    await page.mouse.down();
    await page.mouse.move(end.x, end.y, { steps: 10 });
    await expect(page.locator('.react-flow__selection')).toHaveCount(1);
    await page.mouse.up();

    expect(await view(page)).toBe(before);
    await expect.poll(async () => (await selected(page)).length).toBeGreaterThan(1);
    await expect(page.locator('.details__head h2').first()).toHaveText(/\d+ tables selected/);
});

test('in pan mode a drag on the background moves the diagram', async ({ page }) => {
    await openViewer(page, { hash: 'at=0,0,0.6' });
    await settled(page);
    await page.getByRole('button', { name: /Pan mode/ }).click();
    const start = (await background(page))!;
    const before = await view(page);

    await page.mouse.move(start.x, start.y);
    await page.mouse.down();
    await page.mouse.move(start.x + 150, start.y + 80, { steps: 8 });
    await page.mouse.up();

    expect(await view(page)).not.toBe(before);
    await expect(page.locator('.react-flow__selection')).toHaveCount(0);
});
