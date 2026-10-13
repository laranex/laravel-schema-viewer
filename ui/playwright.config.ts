import { defineConfig, devices } from '@playwright/test';

// Browser tests of the built viewer (../dist), served by e2e/server.mjs. Build first: npm run build.
export default defineConfig({
    testDir: './e2e',
    timeout: 30_000,
    expect: { timeout: 10_000 },
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 1 : 0,
    reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
    use: {
        baseURL: 'http://localhost:4179',
        contextOptions: { reducedMotion: 'reduce' },
        trace: 'retain-on-failure',
    },
    projects: [
        { name: 'chromium', use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 } } },
        { name: 'webkit', use: { ...devices['Desktop Safari'], viewport: { width: 1440, height: 900 } } },
        { name: 'chromium-phone', use: { ...devices['Pixel 7'] } },
        { name: 'webkit-phone', use: { ...devices['iPhone 13'] } },
    ],
    webServer: {
        command: 'node e2e/server.mjs',
        url: 'http://localhost:4179/',
        reuseExistingServer: !process.env.CI,
    },
});
