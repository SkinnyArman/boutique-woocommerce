// End-to-end tests against the local Docker site (docker compose up -d first).
const { defineConfig } = require('@playwright/test');
const fs = require('fs');
const os = require('os');
const path = require('path');

// Use a Chromium already on this machine when Playwright's own download isn't installed
// (its CDN is often unreachable from Iran). Override with PW_CHROME=/path/to/chrome.
function findChrome() {
  if (process.env.PW_CHROME) return process.env.PW_CHROME;
  const cache = path.join(os.homedir(), 'Library/Caches/ms-playwright');
  if (fs.existsSync(cache)) {
    for (const dir of fs.readdirSync(cache).filter((d) => d.startsWith('chromium-')).sort().reverse()) {
      for (const rel of ['chrome-mac-arm64/Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing', 'chrome-mac/Chromium.app/Contents/MacOS/Chromium']) {
        const p = path.join(cache, dir, rel);
        if (fs.existsSync(p)) return p;
      }
    }
  }
  const sys = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
  return fs.existsSync(sys) ? sys : undefined;
}

module.exports = defineConfig({
  testDir: '.',
  testMatch: /.*\.spec\.js/,
  workers: 1,               // one shared database: run in order, never in parallel
  fullyParallel: false,
  timeout: 60_000,
  retries: 0,
  reporter: [['list']],
  globalSetup: require.resolve('./global-setup.js'),
  globalTeardown: require.resolve('./global-teardown.js'),
  use: {
    baseURL: process.env.SITE_URL || 'http://localhost:8080',
    locale: 'fa-IR',
    viewport: { width: 1366, height: 900 },
    screenshot: 'only-on-failure',
    launchOptions: { executablePath: findChrome() },
  },
});
