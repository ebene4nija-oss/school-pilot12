#!/bin/bash
#
# Nightly Postgres backup (LAUNCH.md §2).
#
# Encrypted before it leaves the box, because the dump contains student
# records — names, dates of birth, guardian phone numbers and the encrypted
# medical column. An unencrypted dump sitting in a home directory is the
# single easiest NDPA breach to cause by accident.
#
# Restore is verified monthly per LAUNCH.md §2. A backup you have never
# restored is a hypothesis, not a backup.

set -euo pipefail

STAMP=$(date -u +%Y%m%dT%H%M%SZ)
BACKUP_DIR=/home/schoolpilot/backups
RETAIN_DAYS=14

# Credentials come from the app's own .env so there is one place to rotate
# them. `set -a` exports everything it reads.
set -a
# shellcheck disable=SC1091
source /home/schoolpilot/shared/backend.env
set +a

mkdir -p "$BACKUP_DIR"

DUMP="$BACKUP_DIR/schoolpilot-$STAMP.dump"

# -Fc (custom format) rather than plain SQL: it compresses, and pg_restore can
# pull a single table out of it, which is what you actually want at 2am when
# one table was truncated by mistake rather than the whole database lost.
PGPASSWORD="$DB_PASSWORD" pg_dump \
    --host="${DB_HOST:-127.0.0.1}" \
    --port="${DB_PORT:-5432}" \
    --username="$DB_USERNAME" \
    --dbname="$DB_DATABASE" \
    --format=custom \
    --file="$DUMP"

# Encrypt to the ops public key. Symmetric encryption with a passphrase stored
# on the same box would protect nothing if the box is what gets taken.
gpg --batch --yes --trust-model always \
    --recipient ops@schoolpilot.org.ng \
    --encrypt "$DUMP"
shred -u "$DUMP"

# Offsite. A backup that only exists on the machine it backs up does not
# survive the failure it exists for.
if [ -n "${BACKUP_S3_BUCKET:-}" ]; then
    aws s3 cp "$DUMP.gpg" "s3://$BACKUP_S3_BUCKET/postgres/" --sse AES256
else
    echo "warning: BACKUP_S3_BUCKET unset — backup is local only" >&2
fi

find "$BACKUP_DIR" -name '*.dump.gpg' -mtime +"$RETAIN_DAYS" -delete

echo "$(date -u +%FT%TZ) backup ok: schoolpilot-$STAMP.dump.gpg"
