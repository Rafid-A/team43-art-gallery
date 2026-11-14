# Tests

These checks are optional. The gallery itself runs without Node or Python.

## Task 1 integration gates (Python)

Each gate starts its own uniquely named Docker Compose project and volume, so
it never touches an existing gallery. Run them from `Source`:

```powershell
python tests/era_gate.py
python tests/subject_assignment_gate.py
python tests/painting_add_gate.py
```

- `era_gate.py`: Era identities, and adding and deleting Eras.
- `subject_assignment_gate.py`: subject IDs, assigning subjects to paintings,
  and reruns of the subject migration.
- `painting_add_gate.py`: adding a painting, using the subject gate's setup.

## Browsing and suggestions (Node)

`npm test` runs the browsing suites: sorting and paging for each object type,
title and artist suggestions, and returning to the same list after viewing a
record. It needs Node 20+ and Docker. From this folder, run
`npm ci --ignore-scripts`, then either `npx playwright-core install chromium`
or set `BROWSING_BROWSER` to an installed Edge or Chrome.

Start a fresh test project from `Source`. Pick an unused project name and
unused ports, and never point it at your existing gallery volume:

```powershell
$env:GALLERY_PORT = '18134'
$env:PHPMYADMIN_PORT = '18135'
docker compose -p team43-browsing-test -f compose.yaml -f tests/compose.browsing.yaml up -d --build --wait db web
$env:BROWSING_DB_CONTAINER = 'team43-browsing-test-db-1'
$env:BROWSING_ALLOW_FIXTURE_WRITES = 'yes'
cd tests
npm test
```

The suites add and remove only their own rows, then check that every gallery
table's checksum is the same as before. When you're done, stop and remove
only the `team43-browsing-test` project.
