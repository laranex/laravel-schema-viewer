import { expect, test } from '@playwright/test';
import { closeSmallScreenNote, openViewer } from './helpers';

test('shows a relationship note once wherever the relationship is listed', async ({ page }) => {
    const note = page.locator('.details').first().getByText('The author of the post.');

    for (const hash of ['table=testing:posts', 'table=testing:users', 'column=testing:posts.user_id']) {
        await openViewer(page, { fixture: 'notes', hash });
        // Only the hash changed, so load the page again for it.
        await page.reload();
        await closeSmallScreenNote(page);
        await expect(page.locator('.details__head h2').first()).toBeVisible();
        await expect(note, hash).toHaveCount(1);
    }
});
