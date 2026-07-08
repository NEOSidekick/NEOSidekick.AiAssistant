import { test, expect } from '@playwright/test';

/**
 * E2E tests against the Neos 9 demo project (Neos.Demo site content, English backend UI).
 * Run auth.setup.spec.ts first to create tests/.auth/admin.json.
 */
test.use({ storageState: 'tests/.auth/admin.json', viewport: { width: 1600, height: 900 } });

/** Keep the Sidekick sidebar docked (not fullscreen) so it cannot overlay the inspector. */
const dockSidebar = async (page) => {
    await page.addInitScript(() => {
        window.localStorage.setItem('NEOSidekick', JSON.stringify({ open: true, fullscreen: false }));
    });
};

test.describe('Neos 9 demo: AiAssistant editors (real API)', () => {
    test('focus keyword: generate suggestions on the Home page', async ({ page }) => {
        await dockSidebar(page);
        await page.goto('/neos');
        // The Home document is selected by default; the AI Briefing section is in the inspector.
        // "Calculate with Sidekick" belongs to the FocusKeywordEditor, which renders suggestion
        // buttons ("Generate with Sidekick" on Title Override writes the value directly instead).
        const generateButton = page
            .locator('button.neosidekick__editor__generate-button')
            .filter({ hasText: /Calculate with Sidekick/i })
            .first();
        await expect(generateButton).toBeVisible({ timeout: 60_000 });
        await generateButton.click();
        console.log('[e2e] generate clicked');

        const suggestions = page.locator('button.neosidekick__editor__suggestion-button');
        await expect(suggestions.first()).toBeVisible({ timeout: 90_000 });
        console.log('[e2e] suggestions visible:', await suggestions.count());
        expect(await suggestions.count()).toBeGreaterThanOrEqual(1);

        await expect(page.locator('div[role="alert"]')).toHaveCount(0);

        const first = suggestions.first();
        const value = (await first.textContent())?.trim() ?? '';
        await first.click();
        console.log('[e2e] applied suggestion:', value);
        const input = page.getByRole('textbox', { name: /Focus Keyword/i }).first();
        await expect(input).toHaveValue(value, { timeout: 30_000 });
    });

    test('sidebar chat: authorization workflow completes and chat loads', async ({ page, context }) => {
        await dockSidebar(page);
        await page.goto('/neos');

        const chatFrame = page.frameLocator('iframe[src*="api.neosidekick"]');
        const authButton = chatFrame.getByRole('button', { name: /Open authorization|Autorisierung/i });

        await expect(authButton.or(chatFrame.locator('textarea, [contenteditable="true"]').first()))
            .toBeVisible({ timeout: 60_000 });
        const authVisible = await authButton.isVisible().catch(() => false);
        console.log('[e2e] auth button visible:', authVisible);
        if (authVisible) {
            const popupPromise = context.waitForEvent('page', { timeout: 20_000 });
            await authButton.click();
            const popup = await popupPromise;
            await popup.waitForLoadState('domcontentloaded');
            console.log('[e2e] popup url:', popup.url());

            const continueBtn = popup.getByRole('button', { name: /Continue authorization/i });
            if (await continueBtn.isVisible({ timeout: 10_000 }).catch(() => false)) {
                console.log('[e2e] clicking interstitial');
                await continueBtn.click();
            }

            const authorizeBtn = popup.getByRole('button', { name: /^Authorize$/i });
            await expect(authorizeBtn).toBeVisible({ timeout: 15_000 });
            await authorizeBtn.click();
            console.log('[e2e] authorize clicked');

            // The popup shows "Authorization completed" and may close itself once the chat
            // picks up the token — don't fail if it's already gone.
            const completed = await popup
                .getByText(/Authorization completed/i)
                .isVisible({ timeout: 15_000 })
                .catch(() => false);
            console.log('[e2e] authorization completed page shown:', completed);
            await popup.close().catch(() => undefined);
        }

        // The chat should now render its input (it reloads itself after the popup completes;
        // fall back to one page reload if it doesn't react within 30s).
        const chatInput = () => page
            .frameLocator('iframe[src*="api.neosidekick"]')
            .locator('textarea, [contenteditable="true"], input[type="text"]')
            .first();
        const ready = await chatInput().isVisible({ timeout: 30_000 }).catch(() => false);
        console.log('[e2e] chat ready without reload:', ready);
        if (!ready) {
            await page.reload();
        }
        await expect(chatInput()).toBeVisible({ timeout: 60_000 });
    });
});
