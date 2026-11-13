# Browsing regression checks

These optional developer tests use Node 20+, Docker, and a pinned Playwright
test dependency. The gallery itself needs no Node installation. From this
directory, run `npm ci --ignore-scripts`, then
`npx playwright-core install chromium`. Alternatively, set `BROWSING_BROWSER`
to an installed Chromium/Edge executable. `BROWSING_PLAYWRIGHT` can select an
existing Playwright module for local development.

Use a new, uniquely named disposable Compose project with unused loopback
ports. From `Source`, start it using both `compose.yaml` and
`tests/compose.browsing.yaml`. Do not use an existing gallery volume or an
existing-volume override. The tests verify live container and database-volume
ownership labels, project, service identities and the published loopback port.

For example, in PowerShell from `Source`:

```powershell
$env:GALLERY_PORT = '18134'
$env:PHPMYADMIN_PORT = '18135'
docker compose -p team43-browsing-test-unique -f compose.yaml -f tests/compose.browsing.yaml up -d --build --wait db web
$env:BROWSING_DB_CONTAINER = 'team43-browsing-test-unique-db-1'
$env:BROWSING_ALLOW_FIXTURE_WRITES = 'yes'
cd tests
npm test
```

Select an unused project name and ports for each run. `BROWSING_URL` and
`BROWSING_WEB_CONTAINER` may be supplied explicitly; otherwise the verified
web container and its loopback port determine them. The fresh classroom
accounts and database grants must be present. The database password and gallery
login in these tests are the public classroom defaults.

The suites check independent database ordering/counts, navigation state,
literal prefix matching, keyboard/mouse/touch selection, delayed/failed
requests, no-JavaScript browsing, and responsive controls. Mutation tests require
the explicit opt-in above. They create and remove only their own rows. The
integration suite temporarily retains the complete Eras table under a test
name to verify the legacy view-only layout, then restores it in `finally`.
All eight baseline table checksums must match afterward. Keep this fixture
exclusive to the test run; do not attach any other writer.

Stop only the disposable project after testing. Inspect its ownership before
removing its fresh test volume; never remove an existing gallery's volume.

## Account regressions

With the same labelled, exclusive disposable fixture, classroom accounts,
optional Playwright dependency and `BROWSING_ALLOW_FIXTURE_WRITES=yes`, run:

```powershell
node accounts_regression.cjs
```

This focused suite checks registration validation (including NUL passwords,
duplicates and forged roles), fresh/legacy sessions, client/admin access,
session-ID/CSRF refresh, disabled/deleted accounts, and username reuse with
both the same and a different password, including later promotion. Legacy
server sessions are created only through container PHP CLI, with no web bypass.

Migration probes run as root only inside the ownership-verified fixture. They
temporarily retain the original `art_users` table under a random test name,
exercise initial bootstrap, partial-schema refusal and no-admin reruns with a
later public `user1`, then restore the original table in `finally`. Do not run
another writer or suite concurrently. Account names/password hashes/roles/status
and all eight gallery table checksums must match afterward. Only owned account
rows are removed; the fixture lifecycle belongs to its operator.
