#!/usr/bin/env bash
#
# Activate an uploaded marketing-site release (https://schoolpilot.org.ng/).
#
#   sudo deploy/cpanel/deploy-site.sh /home/schoolpilotorg/uploads/schoolpilot-site-abc1234.tar.gz
#
# The site is static files: no database, no services to restart. Releases are
# unpacked beside each other under site/releases and switched by moving the
# site/current symlink, which Apache serves (apache/schoolpilot.conf, "The
# marketing site on the apex"). Roll back by pointing it at the previous one.

set -euo pipefail

TARBALL="${1:?usage: deploy-site.sh /path/to/schoolpilot-site-<sha>.tar.gz}"
USER=schoolpilotorg
SITE=/home/$USER/site
RELEASES=$SITE/releases
CURRENT=$SITE/current
KEEP=5

STAMP=$(date -u +%Y%m%dT%H%M%SZ)
TARGET=$RELEASES/$STAMP

say() { echo -e "\n==> $*"; }

say "Unpacking $TARBALL"
mkdir -p "$TARGET"
tar -xzf "$TARBALL" -C "$TARGET" --strip-components=1

[ -f "$TARGET/index.html" ] && [ -f "$TARGET/404.html" ] || {
    echo "fatal: $TARBALL does not look like a site release (no index.html / 404.html)" >&2
    rm -rf "$TARGET"
    exit 1
}

# Built on Windows, the archive carries 0777/0666; Apache only needs to read.
chown -R $USER:$USER "$SITE"
find "$TARGET" -type d -exec chmod 755 {} +
find "$TARGET" -type f -exec chmod 644 {} +
chmod 755 "$SITE" "$RELEASES"

# Apache serves these as its own user (nobody on cPanel), so it must be able
# to traverse /home/$USER. cPanel's default home mode (711) allows that; this
# checks rather than changing the home directory's permissions itself.
if id nobody >/dev/null 2>&1 && ! sudo -u nobody test -r "$TARGET/index.html"; then
    echo "warning: Apache's user cannot read $TARGET/index.html." >&2
    echo "         Check the mode of /home/$USER (cPanel's default is 711)." >&2
fi

say "Switching current -> $STAMP"
ln -sfn "$TARGET" "$CURRENT.tmp"
mv -Tf "$CURRENT.tmp" "$CURRENT"
chown -h $USER:$USER "$CURRENT"

say "Checking https://schoolpilot.org.ng/"
code=$(curl -s -o /dev/null -w '%{http_code}' https://schoolpilot.org.ng/)
if [ "$code" != "200" ]; then
    echo "warning: the apex answered $code, not 200." >&2
    echo "         Is the updated apache/schoolpilot.conf installed? See README §2.5." >&2
else
    echo "    apex serves the site (200)"
fi
api=$(curl -s -o /dev/null -w '%{http_code}' https://schoolpilot.org.ng/api/v1/public/site-config)
echo "    /api on the apex still reaches Laravel ($api)"

say "Pruning old site releases (keeping $KEEP)"
ls -1dt "$RELEASES"/*/ | tail -n +$((KEEP + 1)) | xargs -r rm -rf

echo
echo "deployed site $(cat "$CURRENT/RELEASE" 2>/dev/null || echo "$STAMP")"
echo
echo "To roll back:"
echo "  ln -sfn \$(ls -1dt $RELEASES/*/ | sed -n 2p) $CURRENT"
