import { expect, type Page } from '@playwright/test';

// Errors the page logged or threw, collected from the moment the page opens.
export function watchErrors(page: Page): string[] {
    const errors: string[] = [];
    page.on('pageerror', (e) => errors.push(e.message));
    page.on('console', (m) => {
        if (m.type() === 'error') {
            errors.push(m.text());
        }
    });
    return errors;
}

// Open the viewer on a fixture and wait until the diagram has drawn its cards: no fixed sleeps, the layout runs async.
export async function openViewer(page: Page, { fixture = 'blog', hash = '' }: { fixture?: string; hash?: string } = {}): Promise<void> {
    await page.goto(`/?fixture=${fixture}${hash ? `#${hash}` : ''}`);
    await expect(page.locator('.react-flow__node-table').first()).toBeVisible();
}

// Close the note a phone shows on first visit, so it covers nothing.
export async function closeSmallScreenNote(page: Page): Promise<void> {
    const note = page.locator('.screen-note');
    if (await note.isVisible()) {
        await note.getByRole('button', { name: 'Close' }).click();
    }
}
