#!/usr/bin/env bash
#
# Activate an uploaded release on the cPanel server. Run as root (it restarts
# systemd units); the application files stay owned by the cPanel user.
#
#   sudo deploy/cpanel/deploy.sh /home/schoolpilotorg/uploads/schoolpilot-abc1234.tar.gz
#
# Releases are unpacked beside each other and switched by moving one symlink,
# so a bad release is undone by moving it back — see rollback at the bottom.

set -euo pipefail

TARBALL="${1:?usage: deploy.sh /path/to/schoolpilot-<sha>.tar.gz}"
USER=schoolpilotorg
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
#
# A failure here stops the deploy. It used to be swallowed — stderr to
# /dev/null and a "skipped (first deploy?)" note whatever the real reason —
# which is how this platform ran for its first day of deploys with no working
# backup at all and nobody any the wiser. The message was even reassuring.
#
# The genuine first-deploy case is the one where `current` does not exist yet;
# that is detected rather than assumed. Anything else is a real failure, and
# migrating without a backup is the one irreversible thing here.
if [ ! -e "$CURRENT/deploy/cpanel/backup-db.sh" ]; then
    echo "    no previous release — nothing to back up yet"
elif sudo -u $USER "$CURRENT/deploy/cpanel/backup-db.sh"; then
    echo "    backup taken"
elif [ "${SKIP_PREDEPLOY_BACKUP:-0}" = "1" ]; then
    echo "warning: backup failed and SKIP_PREDEPLOY_BACKUP=1 — continuing without one" >&2
else
    cat >&2 <<EOF
fatal: the pre-deploy backup failed, and the next step migrates the database.

Fix the backup first — see deploy/cpanel/backup-db.sh, which prints what it
needs. To deploy anyway, knowing there is no restore point:

  SKIP_PREDEPLOY_BACKUP=1 $0 $TARBALL
EOF
    exit 1
fi

say "Unpacking $TARBALL"
mkdir -p "$TARGET"
tar -xzf "$TARBALL" -C "$TARGET" --strip-components=1
chown -R $USER:$USER "$TARGET"

# Releases built on Windows arrive world-writable: NTFS carries no POSIX mode,
# so tar records 0777/0666 for everything. Apache's suEXEC and PHP-FPM refuse to
# run a script that group- or world-writable, which reads as a blank page or a
# 500 with nothing useful in the vhost log.
find "$TARGET" -type d -exec chmod 755 {} +
find "$TARGET" -type f -exec chmod 644 {} +
chmod 755 "$TARGET"/deploy/cpanel/*.sh

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
# /home/schoolpilotorg/current/backend/public, so nothing in the vhost changes
# between deploys and Apache needs no restart.
ln -sfn "$TARGET" "$CURRENT"
# The link itself is created by root, so it ends up root-owned even though
# everything it points at belongs to the account. `sudo -u $USER` writes through
# it for the rest of this script, and the cPanel user needs to own it.
chown -h $USER:$USER "$CURRENT"

say "Restarting services"
# queue:restart asks running workers to finish the job in hand and exit;
# systemd then starts them again on the new code. Without it the worker keeps
# serving the previous release's classes until its --max-time expires.
sudo -u $USER "$PHP" "$CURRENT/backend/artisan" queue:restart
systemctl restart schoolpilot-worker
systemctl restart schoolpilot-web

# schoolpilot-api runs `artisan serve` with its working directory inside the
# release that was live when it started, so it keeps serving the *previous*
# release's code until restarted. Leaving this out is silent and convincing:
# /up is served by Apache and reports healthy, while every page the web portal
# renders is talking to the old API — so new endpoints 404 and the release looks
# broken in a way the health checks below would not catch.
#
# Fatal rather than a warning if the unit is missing. The alternative is
# finishing a deploy that serves stale code, which is the bug this replaced.
if ! systemctl list-unit-files schoolpilot-api.service --no-legend | grep -q .; then
    echo "fatal: schoolpilot-api.service is not installed — the web portal would" >&2
    echo "       keep calling the previous release. Install it and re-run:" >&2
    echo "         install -m 0644 $TARGET/deploy/cpanel/systemd/schoolpilot-api.service /etc/systemd/system/" >&2
    echo "         systemctl daemon-reload && systemctl enable --now schoolpilot-api" >&2
    exit 1
fi
systemctl restart schoolpilot-api

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

# The portal's own API path, which is the check this script was missing.
#
# Everything above can pass while the admin portal is completely blind: /up is
# served by Apache straight from Laravel, and / is the Next.js shell, which
# renders its chrome before it fetches anything. The portal reaches Laravel
# through a route handler of its own at /api/backend/*, and that path begins
# with /api — so the vhost rule that hands /api to Laravel had been claiming it,
# and Laravel answered "route not found" to every data request the portal made.
# Both checks above were green throughout.
#
# Unauthenticated, so there is no token to manage here. The proxy answers 401
# as JSON when there is no session, which is a perfectly good signal: it proves
# the request reached Next.js rather than Laravel. A 404 means Apache is routing
# it to the wrong application again.
PORTAL_API_STATUS=$(curl -s -o /dev/null -w '%{http_code}' "https://schoolpilot.org.ng/api/backend/user" || echo 000)

case "$PORTAL_API_STATUS" in
    401|200)
        echo "    portal API path healthy (HTTP $PORTAL_API_STATUS)"
        ;;
    404)
        echo "fatal: /api/backend is being served by Laravel, not the portal." >&2
        echo "       The vhost needs its /api/backend rule ABOVE 'ProxyPass /api !'." >&2
        echo "       See deploy/cpanel/apache/schoolpilot.conf. Staying in maintenance mode." >&2
        exit 1
        ;;
    *)
        echo "fatal: portal API path returned HTTP $PORTAL_API_STATUS — staying in maintenance mode" >&2
        exit 1
        ;;
esac

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
echo "  chown -h $USER:$USER $CURRENT"
echo "  systemctl restart schoolpilot-web schoolpilot-worker schoolpilot-api"
echo "Note: rolling back does NOT undo migrations. Restore the pre-deploy"
echo "backup if the new release changed the schema incompatibly."
