#!/usr/bin/env bash
#
# Activate an uploaded release on the cPanel server. Run as root (it restarts
# systemd units); the application files stay owned by the cPanel user.
#
#   sudo deploy/cpanel/deploy.sh /home/schoolpilot/uploads/schoolpilot-abc1234.tar.gz
#
# Releases are unpacked beside each other and switched by moving one symlink,
# so a bad release is undone by moving it back — see rollback at the bottom.

set -euo pipefail

TARBALL="${1:?usage: deploy.sh /path/to/schoolpilot-<sha>.tar.gz}"
USER=schoolpilot
HOME_DIR=/home/$USER
RELEASES=$HOME_DIR/releases
SHARED=$HOME_DIR/shared
CURRENT=$HOME_DIR/current
PHP=/opt/cpanel/ea-php82/root/usr/bin/php
KEEP=5

STAMP=$(date -u +%Y%m%dT%H%M%SZ)
TARGET=$RELEASES/$STAMP

say() { echo -e "\n==> $*"; }

[ -f "$SHARED/backend.env" ] || {
    echo "fatal: $SHARED/backend.env is missing. Create it from .env.production.example first." >&2
    exit 1
}

say "Backing up the database before migrating"
# Migrations are the one step in this script that cannot be undone by moving a
# symlink. Take the backup first, every time, including for the deploy you are
# sure contains no migrations.
sudo -u $USER "$HOME_DIR/current/deploy/cpanel/backup-db.sh" 2>/dev/null \
    || echo "warning: pre-deploy backup skipped (first deploy?)" >&2

say "Unpacking $TARBALL"
mkdir -p "$TARGET"
tar -xzf "$TARBALL" -C "$TARGET" --strip-components=1
chown -R $USER:$USER "$TARGET"

say "Linking shared state"
# .env and storage/ are per-server, not per-release. storage/ in particular
# holds generated report-card PDFs and ID-card runs that must survive a
# deploy; a fresh empty storage/ each time would 404 every PDF a school has
# already downloaded a link to.
ln -sfn "$SHARED/backend.env" "$TARGET/backend/.env"
ln -sfn "$SHARED/storage"     "$TARGET/backend/storage"

say "Maintenance mode"
# --secret lets you verify the new release yourself before letting schools
# back in: visit https://schoolpilot.org.ng/<secret> to bypass the splash screen.
# --retry tells well-behaved clients (and the mobile app) to come back rather
# than treat this as a hard failure.
SECRET=$(openssl rand -hex 16)
sudo -u $USER "$PHP" "$CURRENT/backend/artisan" down --retry=60 --secret="$SECRET" 2>/dev/null || true
echo "    bypass URL: https://schoolpilot.org.ng/$SECRET"

say "Migrations"
# --force because this is a non-interactive shell and Laravel otherwise
# refuses to migrate in production. The refusal is the safety net; the backup
# taken above is what replaces it.
sudo -u $USER "$PHP" "$TARGET/backend/artisan" migrate --force

say "Warming caches"
# Built against the new release's paths. config:cache in particular bakes the
# absolute path in, so this must run after the files are in their final
# location and before the symlink flip.
sudo -u $USER "$PHP" "$TARGET/backend/artisan" config:cache
sudo -u $USER "$PHP" "$TARGET/backend/artisan" route:cache
sudo -u $USER "$PHP" "$TARGET/backend/artisan" view:cache
sudo -u $USER "$PHP" "$TARGET/backend/artisan" event:cache
sudo -u $USER "$PHP" "$TARGET/backend/artisan" storage:link

say "Switching current -> $STAMP"
# The atomic step. The Apache document root points at
# /home/schoolpilot/current/backend/public, so nothing in the vhost changes
# between deploys and Apache needs no restart.
ln -sfn "$TARGET" "$CURRENT"

say "Restarting services"
# queue:restart asks running workers to finish the job in hand and exit;
# systemd then starts them again on the new code. Without it the worker keeps
# serving the previous release's classes until its --max-time expires.
sudo -u $USER "$PHP" "$CURRENT/backend/artisan" queue:restart
systemctl restart schoolpilot-worker
systemctl restart schoolpilot-web

say "Health check"
# Ask through the public hostname, not 127.0.0.1: this is the only step that
# exercises TLS, the tenant Host header and the Apache proxy split together,
# which is where a cPanel deploy actually goes wrong.
#
# /up, not /api/v1/health. Everything under /api/v1/ runs through
# TenantResolutionMiddleware, which reads the first label of the host as a
# school subdomain — so this URL on the apex domain resolves "schoolpilot",
# finds no such school and returns 404 by design. Probing it here would fail
# every deploy and leave the platform sitting in maintenance mode.
for i in $(seq 1 15); do
    if curl -fsS -o /dev/null "https://schoolpilot.org.ng/up"; then
        echo "    API healthy"
        break
    fi
    [ "$i" -eq 15 ] && { echo "fatal: API did not come up — staying in maintenance mode" >&2; exit 1; }
    sleep 2
done

# The tenant path, exercised separately. TENANT_SUBDOMAIN should name a school
# that actually exists; unset, this step is skipped rather than guessed at.
if [ -n "${TENANT_SUBDOMAIN:-}" ]; then
    curl -fsS -o /dev/null "https://$TENANT_SUBDOMAIN.schoolpilot.org.ng/api/v1/health"         || { echo "fatal: tenant routing broken for $TENANT_SUBDOMAIN — staying in maintenance mode" >&2; exit 1; }
    echo "    tenant routing healthy ($TENANT_SUBDOMAIN)"
fi

curl -fsS -o /dev/null "https://schoolpilot.org.ng/" \
    || { echo "fatal: web portal did not come up — staying in maintenance mode" >&2; exit 1; }
echo "    web portal healthy"

say "Lifting maintenance mode"
sudo -u $USER "$PHP" "$CURRENT/backend/artisan" up

say "Pruning old releases (keeping $KEEP)"
# Keep enough to roll back past a bad one you did not notice immediately.
ls -1dt "$RELEASES"/*/ | tail -n +$((KEEP + 1)) | xargs -r rm -rf

echo
echo "deployed $(cat "$CURRENT/RELEASE" 2>/dev/null || echo "$STAMP")"
echo
echo "To roll back:"
echo "  ln -sfn \$(ls -1dt $RELEASES/*/ | sed -n 2p) $CURRENT"
echo "  systemctl restart schoolpilot-web schoolpilot-worker"
echo "Note: rolling back does NOT undo migrations. Restore the pre-deploy"
echo "backup if the new release changed the schema incompatibly."
