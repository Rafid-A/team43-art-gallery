#!/bin/sh
set -eu
cd -- "$(dirname -- "$0")"
docker compose down
echo 'Stopped. Database rows are preserved in the Docker volume.'
