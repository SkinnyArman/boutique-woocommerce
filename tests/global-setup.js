const fs = require('fs');
const { wpcli, FIXTURE_FILE, SHOP_FILE } = require('./helpers');

module.exports = async () => {
  const out = wpcli(['eval-file', '/scripts/test-fixture.php', 'create']);
  const json = out.split('\n').filter((l) => l.startsWith('{')).pop();
  if (!json) throw new Error('Could not create the test fixture. Is the site running? (docker compose up -d)\n' + out);
  fs.writeFileSync(FIXTURE_FILE, json);
  const shop = wpcli(['eval', 'echo wp_json_encode(boutique_shop());']).split('\n').filter((l) => l.startsWith('{')).pop();
  fs.writeFileSync(SHOP_FILE, shop);
};
