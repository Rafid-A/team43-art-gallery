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

## WikiArt import gate (Python)

```powershell
python tests/wikiart_import_gate.py
```

It uses its own Docker Compose project and volume, like the Task 1 gates. It
runs the extractor in containers with no network and PHP's network functions
disabled: the exact three artists, fifteen paintings, full dates and file
traces, plus a diagnostic for each damaged-source case (missing, changed,
cut-off or non-JPEG files, missing fields, cut-off or invalid HTML, emoji,
paths outside the folder, an unlisted painting, a wrong count, a swapped
image, and scripts in a page that must not run). It runs the generated SQL
twice on two copies of the database (one with `NO_BACKSLASH_ESCAPES`) with
quotes, backslashes, Unicode and a long biography. On the page it checks
access control, a forced failure and a held lock that roll back completely,
the import, a repeat import, re-importing a deleted artwork, the
missing-upgrade gate, and that every record that existed before is unchanged.

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

The writing suites remove their own rows; two also compare table checksums.
When you're done, stop and remove only the `team43-browsing-test` project:

```sh
cd ..
docker compose -p team43-browsing-test -f compose.yaml -f tests/compose.browsing.yaml down -v
```

Never run `down -v` on the gallery project (`team43-gallery`); it deletes the
gallery's database and uploads.
