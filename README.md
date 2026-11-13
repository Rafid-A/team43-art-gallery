# Art Gallery

Project Task 01 is a PHP/Apache/MariaDB art gallery with artwork browsing,
title and artist filtering, artwork details, and collection management.

The `project-task-02` branch continues Project Task 01. Its application files
are in `Source`. It includes client and administrator accounts, artist and
painting editing, persistent image uploads, sortable paginated lists, table
summaries, and live title/artist suggestions. The collection adds an artwork-led
opening, compact mobile navigation and filters, artist thumbnails, display
density controls, and an image viewer with bounded zoom and collection navigation.
The public landing introduces selected paintings and an artist story, with
visitor-controlled featured works. Log in to open the catalogue and artwork details.
Offline source extraction and data import remain planned under issue #16.

## Requirements

- Git
- Docker Desktop, or Docker Engine with Docker Compose v2.20 or newer
- Linux containers enabled when using Docker Desktop on Windows

Docker provides PHP, Apache, MariaDB, and phpMyAdmin. Installing PHP or
MySQL directly on your computer is unnecessary for the Docker setup.

## Get the project

```sh
git clone --branch project-task-02 https://github.com/Rafid-A/team43-art-gallery.git
cd team43-art-gallery/Source
```

Run all following Docker commands from the `Source` directory.

## Start the project

Start Docker Desktop or Docker Engine, then run:

```sh
docker compose up -d --build --wait --wait-timeout 180
```

The first build requires internet access.

| Service | Address |
| --- | --- |
| Gallery | http://localhost:8084/artwork/ |
| phpMyAdmin | http://localhost:8085/ |
| Course examples | http://localhost:8084/artwork/examples/ |

### Gallery accounts

| Username | Password | Role |
| --- | --- | --- |
| user1 | pass1 | Administrator |
| user2 | pass2 | Client |
| user3 | pass3 | Client |
| user4 | pass4 | Client |

New visitors can create their own client account from the login page.
Administrators manage accounts on the **Users** page.

phpMyAdmin uses `testuser` / `mypassword`.

These are shared classroom credentials for a local demonstration.

## Change the ports

If ports 8084 or 8085 are occupied, copy `.env.example` to `.env` inside
`Source` and select available ports. For example:

```env
GALLERY_PORT=8086
PHPMYADMIN_PORT=8087
```

Apply the changes:

```sh
docker compose up -d
```

The gallery and phpMyAdmin will then use the selected ports.

## Stop the project

```sh
docker compose down
```

Database records remain in the Docker volume. Avoid adding `-v` unless
you intend to delete that database.

Database initialization runs when a new database volume is created.

## Existing installations and data

The default Compose project `team43-gallery` uses its own
`team43-gallery_gallery-db` volume, separate from the older Task04C
installation. Choose different ports in `.env` when running both. A new
volume is initialized with the supplied classroom data; it does not copy
existing records. For deliberate reuse of an existing volume and its root
password, follow the [runtime guide](Source/README.md#reuse-an-existing-docker-database).

## Current functionality and limitations

Artists, Paintings, Galleries, Genres, and PaintingGenres support
list/detail/add/delete operations with application integrity checks.

Issue 12 adds administrator-only artist and painting editors. Artist edits allow
FirstName, LastName, Nationality, YearOfBirth, and YearOfDeath. Painting edits
allow Title, ArtistID, GalleryID, YearOfWork, and Medium. Primary keys,
painting images, classifications, and all other columns remain immutable from
these forms. Artist years are stored as whole years from -10000 to 2100;
blank or 0 means unknown. Equal known birth and death years are accepted;
death before birth is rejected. Run `09-editor-grants.sql` as described in the
runtime guide when upgrading an existing database.

Signed-in users can save artworks to "My collection" from an artwork page and
see them on their profile (`account.php`). Each user sees and changes only
their own list. Run `10-saved-works.sql` as described in the runtime guide when
upgrading an existing database.

Library text fields (artists, paintings, galleries, genres, eras and
subjects) reject emoji and other characters outside Unicode's Basic
Multilingual Plane, in the browser and on the server. The supplied course
tables use MySQL's 3-byte `utf8` (`utf8mb3`); without this check such
characters were saved as `?` while the form reported success. Converting the
course tables to `utf8mb4` was considered and not done, because it rewrites
every supplied table, index and foreign key on existing installations for a
character range the gallery does not need. Accounts (`art_users`) already use
`utf8mb4` and are unaffected.

Opening a page that requires an account while signed out shows "Please log in
to view that page" and, after logging in, returns to that page with its
filters and position. Only same-folder pages opened with GET are remembered;
otherwise login goes to the role's home page (Artworks for clients,
Administration for administrators).

Task 8 animation: the Home page has a rotating strip, "Across the
collection", with one painting from each of 14 artists in date order. It moves
leftward continuously (a CSS animation of `transform`, about 40px a second),
pauses on hover or keyboard focus, and has a Pause rotation button. With the
operating system's reduced-motion setting, or without JavaScript, it is a
still row that scrolls sideways. Its images load after the rest of the page so
the main painting is not slowed. Other motion: the featured-artwork crossfade
on Home, a growing underline in the navigation bar, the small-screen menu
opening, page fades between gallery pages, and the artwork viewer's zoom.

Ordered paintings cannot be deleted. Permitted painting deletion removes
its classifications and reviews together while preserving the image file.

Subjects support list/detail/add/delete, and a subject that artworks
still use cannot be deleted. Artwork subjects can be assigned from an
artwork's page or from Manage, and removed one assignment at a time;
duplicate pairs are rejected.

Eras support list/detail/add/delete using a stable `EraID`. The six
supplied eras keep IDs 1 to 6 in date order, years stay free text, and an
exact duplicate (same name and years) is rejected. An existing database
needs the era upgrade in the runtime guide; until then Eras stay view-only
and the rest of the gallery keeps working.

## Additional setup information

See [Source/README.md](Source/README.md) for platform launchers and
manual XAMPP setup.

Artwork attribution is recorded in
[image credits](Source/artwork/images/SOURCES.txt).
