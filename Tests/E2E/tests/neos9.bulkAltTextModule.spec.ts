import { test, expect } from '@playwright/test';

/**
 * Bulk-generation flow of the "Image description generator" backend module
 * (alternate-description-generator) on Neos 9, using the REAL NEOSidekick API.
 *
 * Requirements (any Neos 9 site with this plugin installed qualifies, e.g. Neos.Demo):
 * - valid NEOSidekick API key configured
 * - a PUBLICLY reachable base URL (e.g. a ddev share/ngrok domain): the NEOSidekick
 *   API fetches the asset images by URL to describe them
 * - at least one image asset in the media library whose title is empty
 * - backend user with English interface language; auth.setup.spec.ts run first
 *
 * WARNING: this test WRITES generated descriptions into the asset titles of the
 * instance — run against disposable/testing instances only.
 */
test.use({ storageState: 'tests/.auth/admin.json', viewport: { width: 1600, height: 900 } });

test.describe('Neos 9 demo: alt-text backend module (real API)', () => {
    test('configure, bulk-generate and save asset descriptions', async ({ page }) => {
        await page.goto('/neos/ai-assistant/alternate-description-generator');

        // Step 1: configuration view → start
        const startButton = page.getByRole('button', { name: /Start generation|Generierung starten/i });
        await expect(startButton).toBeVisible({ timeout: 30_000 });
        await startButton.click();
        console.log('[e2e] module started');

        // Step 2: list view shows asset items with editors
        const textareas = page.locator('#appContainer textarea');
        await expect(textareas.first()).toBeVisible({ timeout: 60_000 });
        const itemCount = await textareas.count();
        console.log('[e2e] asset items on page:', itemCount);
        expect(itemCount).toBeGreaterThanOrEqual(1);

        // Remember which asset is listed first (persistence check below is identity-based:
        // page counts are unreliable because the module refills pages from the asset pool)
        const firstImageSrc = await page.locator('#appContainer img').first().getAttribute('src');

        // Step 3: the asset module auto-generates descriptions on load (no per-item
        // Generate button) — wait for the real API to fill the first textarea
        await expect(textareas.first()).not.toHaveValue('', { timeout: 120_000 });
        const generated = await textareas.first().inputValue();
        console.log('[e2e] generated description:', generated.slice(0, 80));
        expect(generated.length).toBeGreaterThan(10);

        // Step 4: save (persists via the updateAssets endpoint)
        const saveAll = page.getByRole('button', { name: /Save all and get next page|Alle speichern/i }).first();
        await expect(saveAll).toBeVisible({ timeout: 30_000 });
        await saveAll.click();
        console.log('[e2e] save clicked');

        // Saving either advances to the next page or shows the saved state; there must be no alert
        await page.waitForTimeout(5_000);
        await expect(page.locator('#appContainer [role="alert"], #appContainer .neos-error')).toHaveCount(0);

        // Step 5: persistence — the module lists only assets whose editable property is
        // empty, so after saving, the previously-first asset must no longer be listed first
        // (or the list is empty when it was the last unset asset).
        await page.goto('/neos/ai-assistant/alternate-description-generator');
        await page.getByRole('button', { name: /Start generation|Generierung starten/i }).click();
        const anyItemAfter = await page.locator('#appContainer textarea').first()
            .isVisible({ timeout: 60_000 }).catch(() => false);
        if (anyItemAfter) {
            const firstImageSrcAfter = await page.locator('#appContainer img').first().getAttribute('src');
            console.log('[e2e] first asset before:', firstImageSrc, 'after:', firstImageSrcAfter);
            expect(firstImageSrcAfter).not.toBe(firstImageSrc);
        } else {
            console.log('[e2e] list empty after save — saved asset was the last unset one');
        }
    });
});
