const { spawnSync } = require('node:child_process');
const path = require('node:path');
require('./browsing_test_config.cjs').configure({ writes: true });

for (const name of ['browsing_directory_oracle.cjs', 'suggestions_smoke.cjs', 'suggestions_fixture.cjs', 'browsing_directory_mutations.cjs', 'browsing_integration.cjs']) {
  console.log(`Running ${name}`);
  const result = spawnSync(process.execPath, [path.join(__dirname, name)], { stdio: 'inherit', env: process.env });
  if (result.status !== 0) process.exit(result.status || 1);
}
console.log('All browsing and suggestion regression suites passed.');
