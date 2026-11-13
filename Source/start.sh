#!/bin/sh
set -eu
cd -- "$(dirname -- "$0")"

if ! command -v docker >/dev/null 2>&1; then
    echo 'Install Docker Desktop (or Docker Engine with Compose on Linux). See README.md.' >&2
    exit 1
fi
if ! docker compose version >/dev/null 2>&1; then
    echo 'Docker Compose v2 is required. Update Docker Desktop or install the Compose plugin.' >&2
    exit 1
fi
if ! docker info >/dev/null 2>&1; then
    if [ "$(uname -s)" = Darwin ] && command -v open >/dev/null 2>&1; then
        open -a Docker
        attempt=0
        until docker info >/dev/null 2>&1; do
            attempt=$((attempt + 1))
            if [ "$attempt" -ge 60 ]; then
                echo 'Docker did not become ready. Start Docker Desktop and try again.' >&2
                exit 1
            fi
            sleep 2
        done
    else
        echo 'Start Docker Desktop or the Docker Engine service, then try again.' >&2
        exit 1
    fi
fi

docker compose up -d --build --wait --wait-timeout 180
gallery_url="http://$(docker compose port web 80)/artwork/art-login.php"
admin_url="http://$(docker compose port phpmyadmin 80)/"
printf '\nGallery: %s\nphpMyAdmin: %s\nAdministrator: user1 / pass1\nClient: user2 / pass2\n' "$gallery_url" "$admin_url"
if [ "$(uname -s)" = Darwin ]; then
    open "$gallery_url" "$admin_url"
fi
