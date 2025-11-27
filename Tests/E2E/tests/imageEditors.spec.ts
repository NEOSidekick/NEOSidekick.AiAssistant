import { test, expect } from '@playwright/test';

test.use({ storageState: 'tests/.auth/admin.json' });

test.describe('Image Alt/Text Editors', () => {
    test.beforeEach(async ({ page }) => {
        await page.goto('/neos');
        // Select requested content node by name (robust against dynamic ids)
        await page
            .locator('a[role="treeitem"][data-neos-integrational-test="tree__item__nodeHeader__itemLabel"]', { hasText: 'Test case for ImageAltTextEditor and ImageTitleEditor' })
            .first()
            .click();
    });

    test('Prepare: both textareas have non-empty values', async ({ page }) => {
        const alt = page.locator('textarea#__neos__editor__property---alternativeText');
        const title = page.locator('textarea#__neos__editor__property---imageTitle');
        await expect(alt).toBeVisible();
        await expect(title).toBeVisible();
        await expect(alt).not.toHaveValue('');
        await expect(title).not.toHaveValue('');
    });

    test('Unsetting an image clears both fields', async ({ page }) => {
        // Click Entfernen
        await page.locator('button[title="Entfernen"]').first().click();

        // Both fields empty
        await expect(page.locator('textarea#__neos__editor__property---alternativeText')).toHaveValue('');
        await expect(page.locator('textarea#__neos__editor__property---imageTitle')).toHaveValue('');

        await expect(page.locator('div[role="alert"]')).toHaveCount(0, { timeout: 0 });
    });

    test('Setting new image auto-fills alt/title (autogeneration check)', async ({ page }) => {
        // Stub NEOSidekick generate to return empty string (avoid external/network errors)
        await page.route('**/api/v1/chat?language=*', async route => {
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ data: { message: { message: 'example text' } } })
            });
        });
        // Open media browser
        await page.locator('button[title="Medien"]').first().click();

        // Pick the figure by title
        await page.locator('figure[title="hero-image-01.jpg"]').first().click();

        // Spinner appears and then disappears
        const spinners = page.locator('[data-icon="spinner"]');
        await expect(spinners.first()).toBeVisible({ timeout: 60_000 });
        await expect(spinners).toHaveCount(0, { timeout: 60_000 });

        // Both fields got filled
        await expect(page.locator('textarea#__neos__editor__property---alternativeText')).toHaveValue('example text', { timeout: 60_000 });
        await expect(page.locator('textarea#__neos__editor__property---imageTitle')).toHaveValue('example text', { timeout: 60_000 });

        // No error alerts
        await expect(page.locator('div[role="alert"]')).toHaveCount(0, { timeout: 0 });
    });
});


