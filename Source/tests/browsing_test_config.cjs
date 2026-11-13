const { execFileSync } = require('node:child_process');

function inspect(container) {
  return JSON.parse(execFileSync('docker', ['inspect', container], { encoding: 'utf8' }))[0];
}

function configure({ writes = false } = {}) {
  const env = process.env;
  if (!env.BROWSING_DB_CONTAINER) throw Error('Set BROWSING_DB_CONTAINER to an isolated test database container.');
  env.BROWSING_WEB_CONTAINER ||= env.BROWSING_DB_CONTAINER.replace(/-db-1$/, '-web-1');
  const db = inspect(env.BROWSING_DB_CONTAINER);
  const web = inspect(env.BROWSING_WEB_CONTAINER);
  const project = db.Config.Labels['com.docker.compose.project'];
  for (const [container, service] of [[db, 'db'], [web, 'web']]) {
    const labels = container.Config.Labels;
    if (labels['t3.fixture'] !== 'issue13-browsing-review' || labels['t3.issue'] !== '13'
        || labels['com.docker.compose.project'] !== project || labels['com.docker.compose.service'] !== service) {
      throw Error('Refusing a container without matching disposable browsing-fixture labels.');
    }
  }
  const dataMount = db.Mounts.find(mount => mount.Destination === '/var/lib/mysql');
  if (dataMount?.Type !== 'volume') throw Error('The browsing fixture requires its own labelled database volume.');
  const volume = JSON.parse(execFileSync('docker', ['volume', 'inspect', dataMount.Name], { encoding: 'utf8' }))[0];
  if (volume.Labels?.['t3.fixture'] !== 'issue13-browsing-review' || volume.Labels?.['t3.issue'] !== '13'
      || volume.Labels?.['com.docker.compose.project'] !== project) {
    throw Error('Refusing a database volume without matching disposable browsing-fixture ownership.');
  }
  const ports = web.NetworkSettings.Ports['80/tcp'] || [];
  const port = ports.find(p => ['127.0.0.1', '::1'].includes(p.HostIp))?.HostPort;
  if (!port) throw Error('The test web container must publish a loopback port.');
  env.BROWSING_URL ||= `http://127.0.0.1:${port}/artwork/`;
  const url = new URL(env.BROWSING_URL);
  if (url.protocol !== 'http:' || !['localhost', '127.0.0.1', '[::1]'].includes(url.hostname)
      || url.port !== port || url.pathname !== '/artwork/' || url.username || url.password || url.search || url.hash) {
    throw Error('BROWSING_URL must identify the verified fixture web port and /artwork/ path.');
  }
  env.BROWSING_PLAYWRIGHT ||= require.resolve('playwright-core');
  if (writes && env.BROWSING_ALLOW_FIXTURE_WRITES !== 'yes') throw Error('Set BROWSING_ALLOW_FIXTURE_WRITES=yes for disposable-fixture mutation tests.');
  return { base: env.BROWSING_URL, db: env.BROWSING_DB_CONTAINER, web: env.BROWSING_WEB_CONTAINER };
}

module.exports = { configure };
