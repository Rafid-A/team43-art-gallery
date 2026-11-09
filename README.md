# The Art Library — Team 43

Project Task 01 is a PHP/Apache/MariaDB art gallery with artwork browsing,
title and artist filtering, artwork details, and collection management.

The `project-task-02` branch continues Project Task 01. Its application files
are in `Source`; the planned Task 2 features are not yet implemented.

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
| Gallery | http://localhost:8084/artwork/art-login.php |
| phpMyAdmin | http://localhost:8085/ |
| Course examples | http://localhost:8084/artwork/examples/ |

### Gallery accounts

| Username | Password |
| --- | --- |
| user1 | pass1 |
| user2 | pass2 |
| user3 | pass3 |
| user4 | pass4 |

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
