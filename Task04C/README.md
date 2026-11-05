# The Art Library · Runtime guide

Team 43's local PHP/Apache/MariaDB gallery retains the course artwork,
customer accounts and linked sample exercises.

## Requirements and startup

Install Docker Desktop, or Docker Engine with Compose v2.20 or newer. Use
Linux containers on Windows. From this `Task04C` directory, run:

```sh
docker compose up -d --build --wait --wait-timeout 180
```

The first build needs internet access. Docker supplies PHP 8.3, Apache,
PDO MySQL, mysqli, MariaDB 10.11 and phpMyAdmin; no host PHP is required.

| Service | Default local address |
| --- | --- |
| Gallery | http://localhost:8084/artwork/art-login.php |
| phpMyAdmin | http://localhost:8085/ |
| Course examples | http://localhost:8084/artwork/examples/ |

The gallery has four classroom accounts: `user1` / `pass1` through
`user4` / `pass4`. phpMyAdmin uses the separate local database account
`testuser` / `mypassword`. The www13 examples use `bsmith` / `bsmith` and
`pjones` / `pjones`. These shared credentials and loopback-only ports are
for a local classroom demo.

Windows users may run `Start Gallery.bat` and `Stop Gallery.bat`. On macOS,
use the corresponding `.command` files; on macOS/Linux, `sh start.sh` and
`sh stop.sh` also work. Start Docker first on Windows/Linux.

## Ports and data

To change occupied ports, copy `.env.example` to `.env` beside
`compose.yaml`, set `GALLERY_PORT` and `PHPMYADMIN_PORT`, and restart the
project. No `.env` file is required for the defaults.

Stop from this directory with `docker compose down`. The database remains
in its named volume; do not add `-v` unless intentionally erasing it.
Initialization runs only for a new volume. A different Compose project name
selects a separate database, so use the same `-p` name for start and stop.

## Upgrade an existing database

Back up the existing database first and pause all other database writers.
Do not import `art-small.sql`, rerun account initialization, or remove the
database volume to upgrade. From this directory, using the same Compose
project name as the existing installation:

```sh
docker compose stop web phpmyadmin
docker compose build db web
docker compose up -d --no-deps db
docker compose exec -T db sh -c 'exec mariadb --user=root --password="$MARIADB_ROOT_PASSWORD" < /docker-entrypoint-initdb.d/05-subject-identities.sql'
docker compose exec -T db sh -c 'exec mariadb --user=root --password="$MARIADB_ROOT_PASSWORD" < /docker-entrypoint-initdb.d/06-era-identities.sql'
docker compose exec -T db sh -c 'exec mariadb --user=root --password="$MARIADB_ROOT_PASSWORD" < /docker-entrypoint-initdb.d/07-subject-names.sql'
docker compose up -d --wait --wait-timeout 180
```

The era upgrade (`06-era-identities.sql`) follows the same rules as the
subject upgrade: it accepts only the original two-column `Eras` table with
exactly the six supplied eras, gives them `EraID` 1 to 6 by name and years
(never by row order), keeps the original table as `Eras_legacy`, and does
nothing on a database that already has the identity. Any other shape, an
extra or edited era, or an existing `Eras_migrated`/`Eras_legacy` table
stops it before any change. Until it runs, Eras are view-only; the gallery
and its health check do not depend on it.

Stop if the migration command fails; correct the reported prerequisite and
rerun that command before starting the gallery. A failed batch may leave its
migration routine, which the next run replaces safely. Staging cleanup applies
only to a table created by that run. Pre-existing `Subjects_migrated` or
`Subjects_legacy` tables are never overwritten during legacy conversion.

The supported legacy conversion checks the original single-column table and
its exact name/duplicate counts before changing tables. Custom columns,
indexes, triggers, conflicting mappings or unknown references are rejected
without replacing their data. Successful conversion retains the complete
original table as `Subjects_legacy`; keep it with the backup. If a later FK
step fails, the command can resume against the identified table. A valid
identified database keeps its IDs, additional/custom subjects, duplicate names
and legitimate deletions; this migration does not reseed it.

The name correction (`07-subject-names.sql`) accepts the earlier team mapping
or the verified author names for surviving IDs 9–21. A conflicting name stops
the correction without changing any subject names. It preserves all IDs,
assignments, deletions, ID 8 and custom ID 22; it does not insert ID 22 into
an existing database. Its `_SubjectNameCorrection07` journal contains the
completion marker and is needed for rollback. After completion, reruns leave
user data alone. With writers paused, run the following before writers resume
to restore names changed by 07. It refuses if a journaled ID is currently
missing or its name has changed. Deletion followed by manual recreation with
the same ID and name cannot be detected. If writes have occurred, restore the
backup or verify manual changes before deciding how to roll back:

```sh
docker compose exec -T db sh -c 'exec mariadb --user=root --password="$MARIADB_ROOT_PASSWORD" < /opt/gallery-migrations/07-subject-names-down.sql'
```

Until the supported identity schema is present, HTTP health reports not ready
and authenticated gallery pages return a clear upgrade-required response.
For an existing XAMPP database, back it up, pause writers and run only
`artwork/sql/05-subject-identities.sql` and then
`artwork/sql/06-era-identities.sql`, then `artwork/sql/07-subject-names.sql`
with a database migration administrator. The corresponding down script is
`artwork/sql/07-subject-names-down.sql`.

## Collection behavior

Artworks can be filtered by title and artist. Artwork deletion is blocked
when an order refers to the painting; permitted deletion removes its related
classifications and reviews together, while keeping the image file.
The image credits are in `artwork/images/SOURCES.txt`.

Eras use a stable `EraID`: the six supplied eras keep IDs 1 to 6 in date
order and new eras take the next ID. Nothing references Eras, so deleting
one changes no other record. The fresh subject mapping comes from the authors'
[versioned art-small.sql](https://github.com/funwebdev-2nd-ed/ch19-project2-start/blob/200c4bf1846baa26ac87e0014218c15f395fdaba/art-small.sql)
(SHA-256 `5ebf71f31dbb447a02319033da8ee0866654d1af085a069a028cbdeaf0b29aea`).
All 557 `(PaintingSubjectID, PaintingID, SubjectID)` triples match this
project's supplied seed exactly, including duplicates. The source has no ID 8
and has Abstract at IDs 5 and 22. Existing ID 8 is preserved as an earlier
team-created record; its name is not verified by that source. Existing
assignments are never moved or reseeded.

## Optional existing XAMPP installation

Copy `artwork` into `htdocs` and start Apache and MySQL/MariaDB with PDO MySQL
and mysqli. After backing up any existing databases, import `artwork/sql/`
files in order: `art-small.sql`, `02-art-users.sql`, `03-samples.sql`,
`04-xampp-grants.sql`, `05-subject-identities.sql`, then
`06-era-identities.sql`, and `07-subject-names.sql`. Do not import the down
script during initialization. The starter SQL
replaces the `art` database. Open
http://localhost/artwork/art-login.php. Configure `artwork/config.php` or its
DB environment variables for the local server, and enable `.htaccess` so
SQL and protected helper files remain inaccessible through the web.
