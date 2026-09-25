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
#
# SETUP — this script refuses to run until an encryption key exists:
#
#   gpg --batch --quick-generate-key ops@schoolpilot.org.ng default default never
#   gpg --list-keys ops@schoolpilot.org.ng
#
#   # Export the SECRET key and store it somewhere that is not this server.
#   # Without it the backups cannot be read, which makes them decoration.
#   gpg --export-secret-keys --armor ops@schoolpilot.org.ng > ops-backup-key.asc
#
# Restore:
#   gpg --decrypt schoolpilot-<stamp>.dump.gpg > restore.dump
#   pg_restore --host=127.0.0.1 --username=schoolpilot_user --dbname=schoolpilot \
#       --clean --if-exists restore.dump

set -euo pipefail

STAMP=$(date -u +%Y%m%dT%H%M%SZ)
BACKUP_DIR=/home/schoolpilotorg/backups
RETAIN_DAYS=14
RECIPIENT="${BACKUP_GPG_RECIPIENT:-ops@schoolpilot.org.ng}"

# Credentials come from the app's own .env so there is one place to rotate
# them. `set -a` exports everything it reads.
set -a
# shellcheck disable=SC1091
source /home/schoolpilotorg/shared/backend.env
set +a

mkdir -p "$BACKUP_DIR"

DUMP="$BACKUP_DIR/schoolpilot-$STAMP.dump"

# ---------------------------------------------------------------------------
# Never leave a readable dump behind.
#
# This is the bug this script shipped with. `set -e` plus a `gpg` step that
# failed — the ops key was never generated — meant the script exited *after*
# pg_dump wrote the plaintext file and *before* `shred` removed it. Every
# nightly run and every pre-deploy backup therefore left an unencrypted dump of
# every child's record sitting in the home directory, and the retention sweep
# below only ever matched `*.dump.gpg`, so nothing cleaned them up.
#
# The trap makes the plaintext file's lifetime the script's lifetime, whatever
# happens in between.
# ---------------------------------------------------------------------------
cleanup() {
    [ -f "$DUMP" ] && shred -u "$DUMP" 2>/dev/null || true
}
trap cleanup EXIT

# Check the key *before* dumping. Discovering there is no key after writing a
# plaintext copy of the database is the wrong order to find out.
if ! gpg --list-keys "$RECIPIENT" >/dev/null 2>&1; then
    cat >&2 <<EOF
fatal: no GPG public key for $RECIPIENT, so this backup cannot be encrypted.

Student records are not written to disk unencrypted. Create the key first:

  gpg --batch --quick-generate-key $RECIPIENT default default never

Then export the secret key and keep it OFF this server, or the backups will
be unreadable when you need them.
EOF
    exit 1
fi

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
    --recipient "$RECIPIENT" \
    --encrypt "$DUMP"

# The trap shreds $DUMP on the way out; this just does it promptly.
shred -u "$DUMP"

# A zero-length or missing .gpg is not a backup. Check before reporting success,
# so a silent failure cannot masquerade as fourteen days of retention.
if [ ! -s "$DUMP.gpg" ]; then
    echo "fatal: encrypted backup is missing or empty: $DUMP.gpg" >&2
    exit 1
fi

# Offsite. A backup that only exists on the machine it backs up does not
# survive the failure it exists for.
if [ -n "${BACKUP_S3_BUCKET:-}" ]; then
    aws s3 cp "$DUMP.gpg" "s3://$BACKUP_S3_BUCKET/postgres/" --sse AES256
else
    echo "warning: BACKUP_S3_BUCKET unset — backup is local only" >&2
fi

find "$BACKUP_DIR" -name '*.dump.gpg' -mtime +"$RETAIN_DAYS" -delete

# Sweep any plaintext dumps left by the older version of this script. They
# should not exist; if they do, they are the breach this script exists to
# prevent, so they go immediately rather than waiting for a retention window.
find "$BACKUP_DIR" -name '*.dump' -exec shred -u {} + 2>/dev/null || true

echo "$(date -u +%FT%TZ) backup ok: schoolpilot-$STAMP.dump.gpg ($(du -h "$DUMP.gpg" | cut -f1))"
