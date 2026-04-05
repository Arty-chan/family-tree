// @ts-check
const { defineConfig } = require('@playwright/test');

const PORT = 8081;

module.exports = defineConfig({
  globalSetup: './tests/E2E/global-setup.js',
  testDir: './tests/E2E',
  fullyParallel: false,          // tests share DB state, run sequentially
  retries: 0,
  workers: 1,                    // single DB — run files sequentially
  reporter: 'list',
  use: {
    baseURL: `http://localhost:${PORT}`,
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
  },
  projects: [
    { name: 'firefox', use: { browserName: 'firefox' } },
  ],
  webServer: {
    command: `php -S localhost:${PORT} -t public includes/routes.php`,
    port: PORT,
    reuseExistingServer: false,
    env: { FT_ADMIN_PASSWORD: 'testpassword123' },
  },
});
