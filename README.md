# The Art Library — Team 43

Project Task 01 is a PHP/Apache/MariaDB art gallery with artwork browsing,
title and artist filtering, artwork details, and collection management.

The shared project branch is `Project-Task-01`. Application files remain
inside the `Task04C` directory.

## Requirements

- Git
- Docker Desktop, or Docker Engine with Docker Compose v2.20 or newer
- Linux containers enabled when using Docker Desktop on Windows

Docker provides PHP, Apache, MariaDB, and phpMyAdmin. Installing PHP or
MySQL directly on your computer is unnecessary for the Docker setup.

## Get the project

```sh
git clone --branch Project-Task-01 https://github.com/Rafid-A/team43-art-gallery.git
cd team43-art-gallery/Task04C
```

Run all following Docker commands from the `Task04C` directory.

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
`Task04C` and select available ports. For example:

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

## Running alongside an older Task04C installation

The default Docker Compose project name is `team43-task04c`. Another
checkout using that name can reuse the same containers and database volume.

For a separate installation, select unused ports in `.env` and use a
different project name:

```sh
docker compose -p team43-project-task-01 up -d --build --wait --wait-timeout 180
```

Stop that installation with the same project name:

```sh
docker compose -p team43-project-task-01 down
```

Use the same `-p` name consistently. The supplied start/stop launchers use
the default project name.

## Current functionality and limitations

Artists, Paintings, Galleries, Genres, and PaintingGenres support
list/detail/add/delete operations with application integrity checks.

Ordered paintings cannot be deleted. Permitted painting deletion removes
its classifications and reviews together while preserving the image file.

Eras and Subjects can be viewed, but their add/delete operations remain
unavailable because the supplied schema lacks the required stable
identities and SubjectID mapping. Existing painting-subject assignments
can be viewed and removed; new assignments remain unavailable.

## Additional setup information

See [Task04C/README.md](Task04C/README.md) for platform launchers and
manual XAMPP setup.

Artwork attribution is recorded in
[image credits](Task04C/artwork/images/SOURCES.txt).
