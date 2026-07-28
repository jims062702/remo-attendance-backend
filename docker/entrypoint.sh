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

echo "==> Starting php-fpm and nginx"
exec supervisord -c /etc/supervisord.conf
