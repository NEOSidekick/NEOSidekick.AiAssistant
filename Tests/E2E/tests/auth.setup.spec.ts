import { test as setup, expect } from '@playwright/test';
import fs from 'fs';
import path from 'path';

setup('authenticate', async ({ page, context }) => {
    await page.goto('/neos');

    // If we see the login, sign in. Otherwise we are already authenticated.
    const onLogin = page.url().includes('/login');
    if (onLogin) {
        const username = process.env.NEOS_BACKEND_USERNAME || 'admin';
        const password = process.env.NEOS_BACKEND_PASSWORD || 'admin';
        await page.getByRole('textbox', { name: /Benutzername|Username/i }).fill(username);
        await page.getByRole('textbox', { name: /Passwort|Password/i }).fill(password);
        await page.getByRole('button', { name: /Login|Anmelden/i }).click();
    }

    // Wait until the Neos backend UI is visible (document tree button present)
    await expect(page.getByRole('button', { name: /Dokumentbaum|Document Tree/i })).toBeVisible({ timeout: 30000 });

    const authDir = path.join(__dirname, '.auth');
    fs.mkdirSync(authDir, { recursive: true });
    const storagePath = path.join(authDir, 'admin.json');
    await context.storageState({ path: storagePath });
});


