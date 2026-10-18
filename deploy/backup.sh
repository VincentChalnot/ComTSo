#!/bin/bash
# ComTSo production backup: MariaDB dump + tar of the photo originals, timestamped, with retention.
#   ./backup.sh                 (run from anywhere; uses deploy/compose.yaml and deploy/.env)
#   BACKUP_DIR=/srv/backups BACKUP_KEEP_DAYS=30 ./backup.sh
# Exits non-zero on any failure. Photo thumbnails (cache/) are not saved: they are regenerated on demand.
set -euo pipefail

cd "$(dirname "$0")"
BACKUP_DIR=${BACKUP_DIR:-./backups}
BACKUP_KEEP_DAYS=${BACKUP_KEEP_DAYS:-14}
DC="docker compose"
stamp=$(date +%Y%m%d-%H%M%S)

mkdir -p "$BACKUP_DIR"
umask 077

db="$BACKUP_DIR/db-$stamp.sql.gz"
photos="$BACKUP_DIR/photos-$stamp.tar.gz"
trap 'rm -f "$db.part" "$photos.part"' EXIT

# Consistent snapshot without locking; the root password is read inside the container from its secret file.
# shellcheck disable=SC2016  # expanded inside the container, on purpose
$DC exec -T database sh -c 'mariadb-dump -uroot -p"$(cat /run/secrets/database_root_password)" --single-transaction --routines --events comtso' | gzip > "$db.part"
gzip -t "$db.part"
mv "$db.part" "$db"

# Photo originals, read through the (running) app container so no volume name has to be guessed.
$DC exec -T app tar czf - -C /app/var/photos originals > "$photos.part"
gzip -t "$photos.part"
mv "$photos.part" "$photos"

find "$BACKUP_DIR" -maxdepth 1 -type f \( -name 'db-*.sql.gz' -o -name 'photos-*.tar.gz' \) -mtime +"$BACKUP_KEEP_DAYS" -delete

echo "Backup written: $db $photos"
