#!/bin/sh
cd -- "$(dirname -- "$0")"
sh ./start.sh
result=$?
printf '\nPress Enter to close this window.'
read -r answer
exit "$result"
