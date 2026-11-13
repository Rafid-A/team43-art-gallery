# The Art Library · Runtime guide

Team 43's local PHP/Apache/MariaDB gallery retains the course artwork,
customer accounts and linked sample exercises.

## Requirements and startup

Install Docker Desktop, or Docker Engine with Compose v2.20 or newer. Use
Linux containers on Windows. From this `Source` directory, run:

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
`user4` / `pass4`. `user1` is the initial administrator and `user2` to
`user4` are clients. Anyone can create a client account from the login
page. phpMyAdmin uses the separate local database account
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
in its named volume, and uploaded painting images remain in the
`gallery-uploads` volume (by default `team43-gallery_gallery-uploads`).
Rebuilding or recreating the web container keeps them. Do not add `-v`
unless intentionally erasing both. PHP errors are written to the container
log (`docker compose logs web`), not shown in pages.
Initialization runs only for a new volume. A different Compose project name
selects a separate database, so use the same `-p` name for start and stop.

## Reuse an existing Docker database

By default this project uses `team43-gallery_gallery-db`, a separate volume
from the former `team43-task04c_task04c-db`. Verify the actual old volume
name with `docker volume ls` and `docker volume inspect`; a custom project
name may have produced a different name. Back up the database and stop the
old installation and all other writers before attaching its volume. Never
attach the same database volume to two running MariaDB containers.

To reuse it deliberately, create a local, untracked override file such as
`existing-volume.yaml`, replacing the example name with the verified name:

```yaml
volumes:
  gallery-db:
    external: true
    name: team43-task04c_task04c-db
```

Set `GALLERY_ROOT_PASSWORD` in `.env` to the existing database root password
(the former default was `task04c-local-root`). Changing this environment
variable does not change an existing MariaDB password. Use the actual password,
including for the upgrade commands below. Keep `.env` private. Then use the
same explicit files for every Compose command, including stop and upgrade:

```sh
docker compose -f compose.yaml -f existing-volume.yaml up -d --build --wait --wait-timeout 180
docker compose -f compose.yaml -f existing-volume.yaml down
```

The launchers use only the default configuration and do not apply this
override. `external: true` requires an existing volume and prevents Compose
from creating it. Initialization scripts run only on an empty data directory;
do not point the override at an empty volume when expecting existing data.
Verify the schema and follow the upgrade instructions if needed. To keep the
old installation independent, instead use a verified backup/export and import
it into a separate database with the help of a database administrator; never
run the starter seed against the existing database.

## Upgrade an existing database

Back up the existing database first and pause all other database writers.
Do not import `art-small.sql`, rerun account initialization, or remove the
database volume to upgrade. From this directory, using the same Compose
project and files as the existing installation (include `-f compose.yaml -f
existing-volume.yaml` in each command when using the override above):

```sh
docker compose stop web phpmyadmin
docker compose build db web
docker compose up -d --no-deps db
docker compose exec -T db sh -c 'exec mariadb --user=root --password="$MARIADB_ROOT_PASSWORD" < /docker-entrypoint-initdb.d/05-subject-identities.sql'
docker compose exec -T db sh -c 'exec mariadb --user=root --password="$MARIADB_ROOT_PASSWORD" < /docker-entrypoint-initdb.d/06-era-identities.sql'
docker compose exec -T db sh -c 'exec mariadb --user=root --password="$MARIADB_ROOT_PASSWORD" < /docker-entrypoint-initdb.d/07-subject-names.sql'
docker compose exec -T db sh -c 'exec mariadb --user=root --password="$MARIADB_ROOT_PASSWORD" < /docker-entrypoint-initdb.d/08-account-roles.sql'
docker compose exec -T db sh -c 'exec mariadb --user=root --password="$MARIADB_ROOT_PASSWORD" < /docker-entrypoint-initdb.d/09-editor-grants.sql'
docker compose up -d --wait --wait-timeout 180
```

The editor upgrade (`09-editor-grants.sql`) grants UPDATE only on the five
selected artist and five painting columns to existing `testuser` accounts at
`%`, `localhost`, and `127.0.0.1`. Missing hosts are skipped; no accounts,
passwords, records or unrelated privileges are changed. It is safe to repeat.
Fresh Docker databases run it automatically. Existing installations must run
it explicitly after rebuilding the database image; rebuilding alone does not
rerun initialization scripts on a retained volume.

The account upgrade (`08-account-roles.sql`) adds `role` (`client` or
`admin`, default `client`) and `status` (`active` or `disabled`, default
`active`) to `art_users` in one change. Usernames, names, password hashes
and every other table are kept. Only during the initial upgrade that adds
both columns, it makes the trusted classroom `user1` account the administrator
if present; every other account becomes a client. It also gives the gallery's
`testuser` account UPDATE on `art_users` only, for the Accounts page. Rerunning it changes nothing, and
it never resets a role or status chosen later, even if no administrator remains.
The valid role/status columns mark the completed initial upgrade. A table that
already has only one of the columns, or a different definition, stops it before any
change. If no trusted `user1` existed during the initial upgrade (or the
upgrade was interrupted after adding the columns), an operator must verify
the intended account owner and explicitly provision an administrator as root:
`UPDATE art.art_users SET role = 'admin' WHERE username = 'name';`.
Until 08 runs, nobody can log in: the login page returns an
upgrade-required response and the health check reports not ready.

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
`artwork/sql/06-era-identities.sql`, `artwork/sql/07-subject-names.sql`,
`artwork/sql/08-account-roles.sql` and `artwork/sql/09-editor-grants.sql` with
a database migration administrator. The corresponding down script is
`artwork/sql/07-subject-names-down.sql`.

## Collection behavior

### Responsive viewing and motion

The gallery supports narrow phone, tablet, and desktop layouts. On an
artwork page, select **View larger** to open the image viewer; short screens
keep space for the image and allow scrolling when the title is long.
Press **Escape** or select **Close** to return to the artwork.

With a mouse, hovering over a collection image gives a subtle zoom, and
opening the viewer has a short transition. Keyboard actions stay immediate.
The viewer respects the device's reduced-motion preference; without
JavaScript, the image link opens the original local image directly.

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

### Painting image uploads

On **Add artwork**, an administrator can upload a new image or choose an
unused image already on the server. Uploads accept JPEG, PNG or WebP files
of at most 5 MB and 6000 pixels on each side (24 megapixels). The type is
read from the file's contents, not its name. The server decodes the image
and saves a new JPEG of at most 1600 pixels on the longest side under a name
it generates (`upload-` and 32 hex digits). The original file name, metadata
and any extra bytes are never stored, transparent areas become white, and
the usual camera rotations are applied. Damaged or cut-off files, other
types, oversized files and requests from clients or signed-out visitors are
rejected without saving anything. The selected file has a local browser preview;
it is still validated by the server when submitted.

Uploaded images are stored in `artwork/images/uploads/`; bundled course
images stay in `artwork/images/art/works/square-medium/` and are never
changed. The bounded image is encoded in memory first, then every byte is
written to an exclusively created file, with flush and close checks. The
artwork row refers to it only after the insert commits. On a confirmed
failure before COMMIT, only that request's new file is removed, even if
rollback also fails. Filesystem and database writes are not one atomic operation.
If COMMIT is attempted but its outcome cannot be confirmed, the file is retained
and the form warns against retrying until the collection is checked. The server
logs a reconciliation reference (the generated image basename). An administrator
must check `Paintings.ImageFileName` for that reference and the matching JPEG:
retain referenced files, and remove only uploads confirmed unreferenced after
the database connection is restored. A new name never replaces an existing file, so
simultaneous uploads cannot overwrite each other. Deleting an artwork keeps
its image file, as for bundled images. Docker serves the folder from the
`gallery-uploads` volume; `.htaccess` there serves only generated JPEG names
and runs no scripts.

Back up the uploads with the database so image names and files stay
matched. From this directory, with the gallery running:

```sh
docker compose exec -T db sh -c 'exec mariadb-dump --user=root --password="$MARIADB_ROOT_PASSWORD" --single-transaction --databases art' > art-backup.sql
docker compose cp web:/var/www/html/artwork/images/uploads ./uploads-backup
```

To restore the images into a gallery whose database was restored from the
same backup:

```sh
docker compose cp ./uploads-backup/. web:/var/www/html/artwork/images/uploads/
docker compose exec -T web chown -R www-data:www-data /var/www/html/artwork/images/uploads
```

### Sorting and suggestions

The initial collection view features an existing artwork. On phones, **Menu**
opens navigation and **Filters & sort** opens secondary controls while title
search remains visible. Active title/artist filters can be removed individually.
The combined order control submits the same `sort`/`dir` URL fields as before;
native sorting controls remain available without JavaScript. Comfortable and
compact gallery layouts are optional session preferences, and returning from an
artwork restores the exact collection view's scroll position when session storage
is available.

Artist listings use representative paintings, recorded life years and existing
biographies. Browse provides visual discovery links drawn from current artists,
genres and subjects. These features do not add or alter collection records.

The artwork viewer supports fit/reset, actual pixels, zoom and bounded pan with
keyboard, mouse and touch controls. Zoom stops at the original image's pixels.
Previous/next artwork links follow the active title/artist filters and ordering,
including transitions between collection pages, and retain directory return links.
The original image and ordinary navigation links remain usable without scripts.

Thumbnail and detail images use finite responsive widths derived from the actual
JPEG source. `artwork-media.php` accepts only validated basenames, allowlisted
widths and a SHA-256 content version; it serves public image bytes consistently
with the existing public image folders. Originals and upload volumes are not
rewritten. Derivatives are cached in the container's ephemeral PHP temporary
directory and can be regenerated after recreation. Sources are never enlarged;
missing GD or insufficient resizing memory keeps the original image candidate.
The viewer always uses the full existing source. No higher-resolution artwork is
invented when the supplied image is small.

Login and registration support password managers and optional password visibility.
Editing forms group fields and keep Save/Cancel accessible while scrolling;
existing validation, permissions and unsaved-change warnings still apply.

Choose a sort field and ascending or descending order, then select **View
artworks** or **Apply**. New filter/sort submissions start at page one;
page links, details and add/remove return paths retain the selected view.
Related artwork links also retain the collection view and a return link to
the most recent directory, including its page, management mode and sort.
The management page reports totals for all eight object tables.

Typing a title prefix offers local artwork suggestions. **Find an artist
page by last name** offers artist profiles. Use the arrow keys and Enter,
click or tap a result, or press Escape to dismiss the list. Selecting a
suggestion preserves a return link to the current collection. Without
JavaScript, the submitted title search, artist dropdown and sort controls
remain usable.

The authenticated read-only endpoint is
`GET suggestions.php?type=title|artist&prefix=...`. It returns
`{"results":[{"id":123,"label":"Title or artist name","url":"local detail URL"}]}`,
with at most ten results ordered by matching field and ID. An empty prefix
returns an empty result list. Prefixes match literally under the database
collation, including `%`, `_`, and `!`; the limit is 255 Unicode characters.
Errors use `{"error":{"code":"...","message":"..."}}`: 400 for invalid
input, 401 for an expired/missing login, 405 for another HTTP method, and
503 for unavailable database/readiness. Searches never change records.

Optional repeatable browser/database checks are documented in
[tests/README.md](tests/README.md).

### Accounts and roles

Select **Create a client account** on the login page. A user ID is 3 to 32
letters, numbers, dots, dashes or underscores, and must be unique (`User1`
and `user1` are the same ID). Passwords are 8 to 72 characters and are
stored as bcrypt hashes. Registration always creates an active client;
no form field can choose the role.

| | Client | Administrator |
| --- | --- | --- |
| After login | Artworks | Administration main page |
| Browse artworks, artists, galleries, eras, genres, subjects | Yes | Yes |
| Own profile (Account) | Yes | Yes |
| Add, delete or assign records; Manage library; integrity report | No | Yes |
| Accounts page: change type, disable, enable, delete | No | Yes |

The server checks the role on every administration page and every form
that changes data, so a client who submits one of those requests directly
gets 403 and nothing changes. Every request reads the signed-in account
again: a role change applies on the next request with a new session ID and
form token, and a disabled or deleted account is signed out on its next
request. Disabled accounts cannot log in. An administrator cannot change
or delete their own account, and the last active administrator cannot be
removed. Uploads and editing use the same `require_admin()` check; future imports
must enforce it as well.

Sessions are bound on successful login to a server-only fingerprint of the
stored salted password hash. Deleting and registering the same user ID cannot
transfer an old session, even with the same password or a later promotion.
Changing the stored password hash also requires signing in again. Existing
sessions created before this fix lack the fingerprint and must sign in again;
no password hash or fingerprint is sent to the browser.

## Optional existing XAMPP installation

Copy `artwork` into `htdocs` and start Apache and MySQL/MariaDB with PDO MySQL
and mysqli. Image uploads also need the GD extension (with JPEG, and PNG/WebP
for those formats), `upload_max_filesize` of at least 6M, and an
`artwork/images/uploads` folder that Apache can write; without them, Add
artwork offers only images already on the server. After backing up any existing databases, import `artwork/sql/`
files in order: `art-small.sql`, `02-art-users.sql`, `03-samples.sql`,
`04-xampp-grants.sql`, `05-subject-identities.sql`, then
`06-era-identities.sql`, `07-subject-names.sql`, `08-account-roles.sql`, then
`09-editor-grants.sql`. Do not import the down script during initialization.
The starter SQL replaces the `art` database. Open
http://localhost/artwork/art-login.php. Configure `artwork/config.php` or its
DB environment variables for the local server, and enable `.htaccess` so
SQL and protected helper files remain inaccessible through the web.
