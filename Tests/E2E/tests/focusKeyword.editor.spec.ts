import { test, expect } from '@playwright/test';

test.use({ storageState: 'tests/.auth/admin.json' });

test.describe('Inspector Focus Keyword Editor (real API)', () => {
    test('fill focus keyword from Sidekick suggestion', async ({ page }) => {
        await page.goto('/neos');

        // Open a known page in the tree (German UI observed). Click the item label link.
        await page.locator('a[role="treeitem"][data-neos-integrational-test="tree__item__nodeHeader__itemLabel"]', { hasText: 'NEOSidekick Test Seite' }).first().click();

        // 1-2. Click the generate button for Focus Keyword suggestions
        const generateButton = page
            .locator('button.neosidekick__editor__generate-button')
            .filter({ hasText: /Mit Sidekick/i })
            .first();
        await expect(generateButton).toBeVisible({ timeout: 30_000 });
        await generateButton.click();

        // 3. Verify loading state spinner appears
        await expect(page.locator('[data-icon="spinner"]')).toBeVisible({ timeout: 60_000 });

        // Wait for suggestions
        const suggestions = page.locator('button.neosidekick__editor__suggestion-button');
        await expect(suggestions.first()).toBeVisible({ timeout: 60_000 });

        // 4. Verify at least 3 suggestions
        const suggestionCount = await suggestions.count();
        expect(suggestionCount).toBeGreaterThanOrEqual(3);

        // 7. Verify there is no alert on the page
        await expect(page.locator('div[role="alert"]')).toHaveCount(0);

        // Pick suggestion
        const firstSuggestion = suggestions.first();
        const value = (await firstSuggestion.textContent())?.trim() ?? '';
        await firstSuggestion.click();

        // 6. Verify the input value equals selected suggestion
        const input = page.getByRole('textbox', { name: /SEO Fokus-Keyword/i }).first();
        await expect(input).toBeVisible({ timeout: 30_000 });
        await expect(input).toHaveValue(value);
    });
});


