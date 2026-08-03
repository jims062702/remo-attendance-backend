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

echo "==> Seeding reference data and the floor plan"
# Projects, sites, support teams and the 60 machines, then their positions on
# the floor. Without these the tracker form has no project to pick and the PC
# picker has no desks to show -- the daily flow is unusable, which is exactly
# what a freshly migrated production database looked like.
#
# Both seeders are written to be re-run: OperationsLookupSeeder uses
# updateOrCreate, and FloorPlanSeeder is additive by design -- it creates
# missing machines and positions existing ones, and never deletes, renames or
# clears a support flag an admin set by hand. Attendance rows carry a foreign
# key to workstations, so a seeder that renumbered a desk would silently
# rewrite where people sat on past shifts.
php artisan db:seed --class=OperationsLookupSeeder --force \
    || echo "!!! Reference-data seeding failed -- continuing anyway"
php artisan db:seed --class=FloorPlanSeeder --force \
    || echo "!!! Floor-plan seeding failed -- continuing anyway"

echo "==> Ensuring administrators exist"
# The addresses listed in AdministratorSeeder. Sign-in is Google-only and
# accounts are never self-created, so without this a freshly migrated database
# has nobody who can reach the admin screens -- and no way to create anyone,
# because creating users is itself an admin screen. `php artisan user:authorise`
# is the usual way out of that, but Render gives free instances no shell.
#
# Only that one seeder, never `db:seed` on its own: the default DatabaseSeeder
# is demo data -- fictional taskers and three weeks of invented shifts -- and
# running it against production would be a mess to unpick.
#
# Deliberately not fatal. `set -e` is on, and a seeder that fails should cost
# one account, not the whole service.
php artisan db:seed --class=AdministratorSeeder --force \
    || echo "!!! Administrator seeding failed -- continuing anyway"

echo "==> Starting php-fpm and nginx"
exec supervisord -c /etc/supervisord.conf
