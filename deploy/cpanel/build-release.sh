#!/usr/bin/env bash
#
# Build an uploadable release tarball. Run this on your machine or in CI —
# never on the production server.
#
# Why not build on the server: `composer install` and `next build` together
# need more RAM than a school-hours box has to spare, and a failed build would
# leave a half-installed vendor/ directory under the live document root. The
# server only ever unpacks something that already built successfully.
#
#   ./deploy/cpanel/build-release.sh
#   -> dist/schoolpilot-<git-sha>.tar.gz

set -euo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
cd "$ROOT"

SHA=$(git rev-parse --short HEAD)
DIRTY=""
if ! git diff --quiet || ! git diff --cached --quiet; then
    DIRTY="-dirty"
    echo "warning: working tree has uncommitted changes; tagging release $SHA$DIRTY" >&2
fi

RELEASE="schoolpilot-$SHA$DIRTY"
STAGE="dist/$RELEASE"

rm -rf "$STAGE"
mkdir -p "$STAGE/backend" "$STAGE/web" "$STAGE/deploy"

echo "==> backend: composer install (production)"
(
    cd backend
    # --no-dev keeps faker, phpunit and pint off the production box. They are
    # not just wasted space: laravel/pail and tinker widen what a compromised
    # PHP process can reach.
    composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist
)

echo "==> backend: staging"
# Copy the application, not the repository. storage/ and .env are deliberately
# excluded — they live in shared/ on the server and survive deploys.
tar -cf - -C backend \
    --exclude='./storage' \
    --exclude='./.env' \
    --exclude='./tests' \
    --exclude='./.phpunit.cache' \
    . | tar -xf - -C "$STAGE/backend"

echo "==> web: npm ci && next build"
(
    cd web
    npm ci
    npm run build
)

echo "==> web: staging standalone output"
# next.config.ts sets output: "standalone", so the traced server and only the
# node_modules it actually reaches come across. The static assets are not
# inside standalone/ and have to be copied in alongside it, or every page
# renders unstyled with no client-side JS.
mkdir -p "$STAGE/web/.next"
cp -r web/.next/standalone "$STAGE/web/.next/standalone"
cp -r web/.next/static "$STAGE/web/.next/standalone/.next/static"
[ -d web/public ] && cp -r web/public "$STAGE/web/.next/standalone/public"

echo "==> deploy scripts"
cp -r deploy/cpanel "$STAGE/deploy/cpanel"

echo "$SHA" > "$STAGE/RELEASE"
date -u +%FT%TZ > "$STAGE/BUILT_AT"

echo "==> packing"
tar -czf "dist/$RELEASE.tar.gz" -C dist "$RELEASE"
rm -rf "$STAGE"

echo
echo "built dist/$RELEASE.tar.gz ($(du -h "dist/$RELEASE.tar.gz" | cut -f1))"
echo "upload it, then run deploy/cpanel/deploy.sh on the server."
