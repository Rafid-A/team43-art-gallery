#!/bin/sh
cd -- "$(dirname -- "$0")"
sh ./stop.sh
result=$?
printf '\nPress Enter to close this window.'
read -r answer
exit "$result"
