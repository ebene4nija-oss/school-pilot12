#!/usr/bin/env bash
#
# Build the marketing site (site/, served on https://schoolpilot.org.ng/) as an
# uploadable tarball. Run on your machine or in CI, never on the server.
#
#   ./deploy/cpanel/build-site-release.sh
#   -> dist/schoolpilot-site-<git-sha>.tar.gz
#
# The site is a static export. Its build reads the console's Website settings
# (WhatsApp number, contacts, switches, screenshots) from the production API,
# so a change made in Super Admin > Website reaches the site by rebuilding and
# redeploying it. SITE_CONFIG_REQUIRED=1 makes the build fail rather than ship
# without those values if the API can't be reached.

set -euo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
cd "$ROOT"

API_BASE=${API_BASE:-https://schoolpilot.org.ng/api/v1}

SHA=$(git rev-parse --short HEAD)
if ! git diff --quiet -- site || ! git diff --cached --quiet -- site; then
    SHA="$SHA-dirty"
    echo "warning: site/ has uncommitted changes; tagging release $SHA" >&2
fi
RELEASE="schoolpilot-site-$SHA"

echo "==> site: npm ci && next build (config from $API_BASE)"
(
    cd site
    npm ci
    NEXT_PUBLIC_SITE_URL=https://schoolpilot.org.ng \
    NEXT_PUBLIC_TENANT_DOMAIN=schoolpilot.org.ng \
    NEXT_PUBLIC_API_BASE="$API_BASE" \
    SITE_CONFIG_REQUIRED=1 \
        npm run build
    # The weight check; reports the JS figure against the budget in the README.
    npm run check-budget || echo "warning: performance budget exceeded (see site/README.md)" >&2
)

echo "==> packing"
mkdir -p dist
rm -rf "dist/$RELEASE"
cp -r site/out "dist/$RELEASE"
echo "$SHA" > "dist/$RELEASE/RELEASE"
tar -czf "dist/$RELEASE.tar.gz" -C dist "$RELEASE"
rm -rf "dist/$RELEASE"

echo
echo "built dist/$RELEASE.tar.gz ($(du -h "dist/$RELEASE.tar.gz" | cut -f1))"
echo "upload it, then run deploy/cpanel/deploy-site.sh on the server."
