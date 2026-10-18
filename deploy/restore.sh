#!/bin/bash
# ComTSo production restore. DESTRUCTIVE: replaces the database content (and photo originals if a photos archive is given).
#   ./restore.sh --yes backups/db-20260101-030000.sql.gz [backups/photos-20260101-030000.tar.gz]
# The app is stopped during the DB restore and started again afterwards.
set -euo pipefail

cd "$(dirname "$0")"
if [ "${1:-}" != "--yes" ] || [ -z "${2:-}" ]; then
	echo "Usage: $0 --yes <db-dump.sql.gz> [photos.tar.gz]   (this overwrites the current data)" >&2
	exit 2
fi
db=$2
photos=${3:-}
DC="docker compose"

[ -f "$db" ] || { echo "No such file: $db" >&2; exit 1; }
[ -z "$photos" ] || [ -f "$photos" ] || { echo "No such file: $photos" >&2; exit 1; }
gzip -t "$db"
[ -z "$photos" ] || gzip -t "$photos"

$DC stop app
# The dump holds CREATE TABLE statements with DROP TABLE IF EXISTS: it replaces the tables in place.
# shellcheck disable=SC2016  # expanded inside the container, on purpose
gunzip -c "$db" | $DC exec -T database sh -c 'mariadb -uroot -p"$(cat /run/secrets/database_root_password)" comtso'
$DC up -d

if [ -n "$photos" ]; then
	$DC exec -T app tar xzf - -C /app/var/photos < "$photos"
fi
echo "Restore done."
