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

## Upload regressions

With the same exclusive fixture and environment, run `node uploads_regression.cjs`.
The suite additionally requires a writable, named `gallery-uploads` volume with
the same fixture labels and exact Compose project/volume identity. It never
starts, stops or removes containers or volumes. It uses the existing optional
Playwright dependency and an installed Chromium/Edge browser.

Checks cover complete/short/failed stream writes (including `/dev/full`), a real
test-owned PDO connection loss with failed rollback, conservative file retention
after a possible COMMIT, JPEG/PNG/WebP decoding and stripped output, invalid,
damaged, empty and oversized files, admin/client/anonymous requests, CSRF,
generated names, failed INSERT cleanup, separate-session parallel uploads,
selected-file preview/replacement/clear/error/source switching, bundled previews,
untouched and selected-file dirty state, pending submission, and viewport fit.
Images are generated in memory by fixture GD; no external corpus is downloaded.
The temporary trigger rejects only this run's exact title and is removed in
`finally`. Cleanup deletes only rows with this run's marker and their recorded
generated files. All eight gallery tables, accounts, existing upload files and
bundled-image hashes must match the baseline afterward.

The COMMIT ambiguity branch is exercised directly through the cleanup helper;
this suite does not simulate a lost COMMIT reply at the network boundary.
Container recreation/persistence, backup/restore, physical devices and other
browser engines remain separate operator checks. The painting-add Python gate
still uses its own disposable lifecycle; its valid legacy-image form now submits
`image_source=existing` explicitly.
