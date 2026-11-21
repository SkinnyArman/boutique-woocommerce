// Checks against the LIVE site (LIVE_URL, e.g. https://your-shop.ir). No database access, no fixtures:
// it uses the site like a visitor and like the shop manager. One test places a real order and
// cancels + deletes it at the end.
//   LIVE_URL=https://your-shop.ir LIVE_ADMIN_PASS=… LIVE_MANAGER_PASS=… npx playwright test -c tests/live/playwright.live.config.js
const base = require('../playwright.config.js');
if (!process.env.LIVE_URL) throw new Error('Set LIVE_URL, e.g. LIVE_URL=https://your-shop.ir');
const HOST = new URL(process.env.LIVE_URL).hostname;
module.exports = {
  ...base,
  testDir: __dirname,
  testMatch: /.*\.live\.js/,
  globalSetup: undefined,
  globalTeardown: undefined,
  timeout: 120_000,
  use: {
    ...base.use,
    baseURL: process.env.LIVE_URL,
    // LIVE_IP=<server ip> talks to the server directly (bypasses a VPN's DNS / routing).
    launchOptions: { ...base.use.launchOptions, args: process.env.LIVE_IP ? [`--host-resolver-rules=MAP ${HOST} ${process.env.LIVE_IP}, MAP www.${HOST} ${process.env.LIVE_IP}`] : [] },
  },
};
