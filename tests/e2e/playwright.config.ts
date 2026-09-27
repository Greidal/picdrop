import { defineConfig, devices } from '@playwright/test';

/**
 * E2E tests run against a running stack (see compose.e2e.yml / README).
 * Tests share one database, so they run serially.
 */
export default defineConfig({
  testDir: './specs',
  fullyParallel: false,
  workers: 1,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI
    ? [['github'], ['list'], ['html', { open: 'never' }]]
    : [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:18080',
    locale: 'de-DE',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  projects: [
    {
      name: 'desktop-chromium',
      testIgnore: /guest\.spec\.ts/,
      use: { ...devices['Desktop Chrome'] },
    },
    {
      // Guests use the upload page on their phones.
      name: 'mobile-safari',
      testMatch: /guest\.spec\.ts/,
      use: { ...devices['iPhone 15'] },
    },
  ],
});
