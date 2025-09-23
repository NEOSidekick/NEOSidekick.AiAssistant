import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: './tests',
    timeout: 120_000,
    expect: { timeout: 20_000 },
    use: {
        baseURL: process.env.PLAYWRIGHT_BASE_URL || 'https://neosidekicktestwebsite.ddev.site',
        trace: 'retain-on-failure',
        video: 'retain-on-failure',
        screenshot: 'only-on-failure',
        viewport: { width: 1440, height: 900 }
    },
    projects: [
        { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    ],
    reporter: [['list']],
});


