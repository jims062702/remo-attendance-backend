#!/bin/sh
#
# Container start-up.
#
# Everything here runs at RUN TIME rather than build time, and that ordering is
# the point: Render injects environment variables when the container starts,
# not when the image is built. Caching config during the build would bake in
# whatever the values were then -- which is to say, nothing.

set -e

: "${PORT:=8080}"
export PORT

echo "==> Rendering nginx config on port ${PORT}"
# Only PORT is substituted. Left unrestricted, envsubst would also eat nginx's
# own $uri and $query_string and every route would 404.
envsubst '${PORT}' < /etc/nginx/nginx.conf.template > /etc/nginx/nginx.conf

mkdir -p /tmp/nginx-client /tmp/nginx-proxy /tmp/nginx-fastcgi \
         /tmp/nginx-uwsgi /tmp/nginx-scgi

# Storage is ephemeral on Render's free tier, so the framework directories may
# be missing on a cold start even though the image created them.
mkdir -p storage/framework/cache storage/framework/sessions \
         storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache || true

echo "==> Caching configuration"
php artisan config:cache
php artisan route:cache

echo "==> Running migrations"
# --force because this is non-interactive. Migrations are idempotent, so a
# restart re-running them is a no-op rather than a hazard.
php artisan migrate --force

# Bootstrap the first administrator.
#
# Sign-in is Google-only and accounts are never self-created, so a freshly
# migrated database has nobody who can reach the admin screens -- and no way to
# create anyone, because creating users is itself an admin screen. Normally
# `php artisan user:authorise` breaks that circle from a shell, but Render
# gives free instances no shell at all.
#
# Setting BOOTSTRAP_ADMIN_EMAIL in the service environment does the same job at
# start-up instead. Remove the variable once the account exists: leaving it set
# is harmless -- the command updates the existing record rather than duplicating
# it -- but it re-runs on every deploy for no reason, and it would quietly
# restore admin rights to that address if they were ever deliberately revoked.
if [ -n "${BOOTSTRAP_ADMIN_EMAIL:-}" ]; then
    echo "==> Authorising ${BOOTSTRAP_ADMIN_EMAIL} as an administrator"

    # The two branches exist because --name has to be passed as a single
    # argument. Folding it into one command with ${VAR:+--name="$VAR"} looks
    # tidier and is wrong: the expansion is word-split before the command runs,
    # so "Juan Dela Cruz" arrives as three separate arguments.
    #
    # Neither branch is fatal. `set -e` is on, and a typo in the address would
    # otherwise take the whole service down rather than just failing to create
    # one account.
    if [ -n "${BOOTSTRAP_ADMIN_NAME:-}" ]; then
        php artisan user:authorise "${BOOTSTRAP_ADMIN_EMAIL}" --admin \
            --name="${BOOTSTRAP_ADMIN_NAME}" \
            || echo "!!! Could not authorise ${BOOTSTRAP_ADMIN_EMAIL} -- continuing anyway"
    else
        php artisan user:authorise "${BOOTSTRAP_ADMIN_EMAIL}" --admin \
            || echo "!!! Could not authorise ${BOOTSTRAP_ADMIN_EMAIL} -- continuing anyway"
    fi
fi

echo "==> Starting php-fpm and nginx"
exec supervisord -c /etc/supervisord.conf
