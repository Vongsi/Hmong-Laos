# The website and its PHP background tasks (Reverb, the scheduler, the BCEL listener): one image, started with a
# different command per container (deploy/docker-compose.yml). FrankenPHP is PHP with the Caddy web server built
# in, which also gets and renews the HTTPS certificate. Built on the server by deploy/deploy.sh.
FROM dunglas/frankenphp:1-php8.4-bookworm

# PHP extensions: MariaDB, intl (country names in Lao), GD (photo sizes), the rest for Laravel, Statamic and phone
# notifications. libcap lets the web server use ports 80 and 443 without running as root.
RUN apt-get update \
 && apt-get install -y --no-install-recommends unzip libcap2-bin \
 && rm -rf /var/lib/apt/lists/* \
 && install-php-extensions pdo_mysql intl gd exif zip bcmath gmp pcntl opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY deploy/php.ini "$PHP_INI_DIR/conf.d/zz-daim-av-hlub.ini"

WORKDIR /app

# Libraries first, so a change to the site's own code doesn't download them again.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader

# The site itself. What Composer would run after installing (package discovery, Statamic's control panel files)
# runs here; nothing in it needs the database or the server's settings.
COPY . .
RUN mkdir -p storage/app/public storage/app/private storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/logs storage/statamic public/assets bootstrap/cache \
 && composer dump-autoload --no-dev --optimize --no-scripts \
 && php artisan package:discover --ansi \
 && php artisan statamic:install --ansi \
 && php artisan storage:link \
 && mkdir -p /data/caddy /config/caddy \
 && chown -R www-data:www-data storage bootstrap/cache public/assets /data /config \
 && setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp

COPY deploy/Caddyfile /etc/frankenphp/daim-av-hlub.Caddyfile
COPY deploy/entrypoint.sh /usr/local/bin/dah-entrypoint
RUN chmod +x /usr/local/bin/dah-entrypoint

USER www-data
ENTRYPOINT ["dah-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/daim-av-hlub.Caddyfile"]
