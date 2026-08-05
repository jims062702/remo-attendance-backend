# Laravel on Render.
#
# Render has no native PHP runtime, so the service is built as a Docker image.
# nginx serves the static files and hands .php to php-fpm over a local socket;
# supervisord keeps both alive in the one container Render gives us.
#
# `php artisan serve` is deliberately NOT used. PHP's built-in server handles a
# single request at a time, so one slow query blocks the whole floor -- fine for
# a laptop, wrong for anything anyone else is waiting on.

FROM php:8.2-fpm-alpine

# gd and zip are required by PhpSpreadsheet (the Excel exports); the rest are
# Laravel's own baseline.
#
# Both database drivers are installed on purpose. pdo_pgsql is the one that
# matters here -- the deployed database is Render's managed PostgreSQL -- but
# development and the test suite run on MariaDB, and an image that can only
# speak one dialect makes it impossible to reproduce a production problem
# locally against the image itself. The pair costs a few megabytes.
RUN apk add --no-cache \
        nginx supervisor gettext \
        libpng-dev libjpeg-turbo-dev freetype-dev libzip-dev oniguruma-dev \
        postgresql-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        gd zip pdo_mysql pdo_pgsql mbstring bcmath opcache
# The -dev packages are deliberately NOT removed afterwards. Alpine treats the
# runtime libraries as their dependencies, so `apk del libpng-dev` takes libpng
# with it as an orphan -- and gd then fails to load at runtime, which surfaces
# as the Excel export dying rather than as a build error.

# Opcache matters more than usual here: the free instance is 0.1 CPU, and
# recompiling every request is the difference between a slow app and an
# unusable one.
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.memory_consumption=128'; \
        echo 'opcache.max_accelerated_files=10000'; \
        echo 'opcache.validate_timestamps=0'; \
    } > /usr/local/etc/php/conf.d/opcache.ini

# Excel imports are capped at 10 MB by the request validation; PHP has to allow
# at least that or the upload fails before Laravel ever sees it.
RUN { \
        echo 'upload_max_filesize=12M'; \
        echo 'post_max_size=12M'; \
        echo 'memory_limit=256M'; \
    } > /usr/local/etc/php/conf.d/uploads.ini

# php-fpm on the loopback only.
#
# The base image ships zz-docker.conf with `listen = 9000`, which binds every
# interface -- so the container exposes a second, unauthenticated FastCGI port
# alongside nginx. Render's port scanner reports it ("additional ports
# TCP:9000") and the file sorts after zz-docker.conf so this wins.
#
# nginx already connects to 127.0.0.1:9000, so nothing about the request path
# changes; the port simply stops being reachable from outside the container,
# which is what the Dockerfile header claimed all along.
RUN { \
        echo '[www]'; \
        echo 'listen = 127.0.0.1:9000'; \
    } > /usr/local/etc/php-fpm.d/zzz-listen.conf

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Dependencies first, so a code-only change does not re-resolve the whole
# dependency tree on every deploy.
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction \
        --no-scripts --prefer-dist

COPY . .

RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

COPY docker/nginx.conf.template /etc/nginx/nginx.conf.template
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Render injects PORT at runtime; this is only a sensible local default.
ENV PORT=8080
EXPOSE 8080

CMD ["/usr/local/bin/entrypoint.sh"]
