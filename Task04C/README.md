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
docker compose up -d --wait --wait-timeout 180
```

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

Until the supported identity schema is present, HTTP health reports not ready
and authenticated gallery pages return a clear upgrade-required response.
For an existing XAMPP database, back it up, pause writers and run only
`artwork/sql/05-subject-identities.sql` with a database migration administrator.

## Collection behavior

Artworks can be filtered by title and artist. Artwork deletion is blocked
when an order refers to the painting; permitted deletion removes its related
classifications and reviews together, while keeping the image file.
The image credits are in `artwork/images/SOURCES.txt`.

The supplied schema has no immutable Era key. Subject records retain the
explicit ID/name mapping introduced in `f846d9c`, including both Abstract
records. Its external course provenance still needs confirmation. This
upgrade does not invent a replacement mapping or change painting assignments.

## Optional existing XAMPP installation

Copy `artwork` into `htdocs` and start Apache and MySQL/MariaDB with PDO MySQL
and mysqli. After backing up any existing databases, import `artwork/sql/`
files in order: `art-small.sql`, `02-art-users.sql`, `03-samples.sql`,
`04-xampp-grants.sql`, then `05-subject-identities.sql`. The starter SQL
replaces the `art` database. Open
http://localhost/artwork/art-login.php. Configure `artwork/config.php` or its
DB environment variables for the local server, and enable `.htaccess` so
SQL and protected helper files remain inaccessible through the web.
