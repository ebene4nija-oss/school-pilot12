#!/bin/sh
#
# Container entrypoint for the SchoolPilot API.
#
# Three jobs, in order: refuse to start misconfigured, wait for Postgres, warm
# the framework caches. Then hand off to whatever CMD asked for — php-fpm for
# the web container, `artisan queue:work` for the worker.

set -e

fail() {
    echo "entrypoint: $1" >&2
    exit 1
}

# --- 1. Refuse to start misconfigured -------------------------------------
#
# An empty APP_KEY does not fail at boot — it fails later, at the first
# encrypt/decrypt, which for us is a student medical field. Better to never
# accept traffic than to serve a school and lose their encrypted columns.
[ -n "${APP_KEY:-}" ] || fail "APP_KEY is empty. Generate one with 'php artisan key:generate --show' and set it in the environment."

# --- 2. Wait for Postgres --------------------------------------------------
#
# depends_on only waits for the container to exist, not for Postgres to accept
# connections, so the first migrate would race it on a cold start.
if [ "${DB_CONNECTION:-pgsql}" = "pgsql" ]; then
    printf 'entrypoint: waiting for postgres at %s:%s' "${DB_HOST:-postgres}" "${DB_PORT:-5432}"
    attempt=0
    until php -r "exit(@fsockopen(getenv('DB_HOST') ?: 'postgres', (int) (getenv('DB_PORT') ?: 5432)) ? 0 : 1);" 2>/dev/null; do
        attempt=$((attempt + 1))
        [ "$attempt" -lt 60 ] || fail "postgres did not accept connections within 60s"
        printf '.'
        sleep 1
    done
    echo ' up.'
fi

# --- 3. Migrations ---------------------------------------------------------
#
# Opt-in, not automatic. Every container that restarts would otherwise attempt
# a migration, and on a multi-replica deploy they would race each other. Run it
# from one place — the compose file sets this on the api service only.
if [ "${AUTO_MIGRATE:-false}" = "true" ]; then
    echo "entrypoint: running migrations"
    php artisan migrate --force
fi

# --- 4. Warm the caches ----------------------------------------------------
#
# config:cache is the step that makes env() outside config/ return null. That
# is intended: it is the production behaviour, so anything still reading env()
# at runtime should break here, in our own container, rather than quietly
# falling back to a default on a school's server.
php artisan config:cache
php artisan route:cache
php artisan event:cache

exec "$@"
