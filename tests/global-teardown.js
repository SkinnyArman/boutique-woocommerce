const fs = require('fs');
const { wpcli, FIXTURE_FILE } = require('./helpers');

module.exports = async () => {
  wpcli(['eval-file', '/scripts/test-fixture.php', 'delete']);
  fs.rmSync(FIXTURE_FILE, { force: true });
};
