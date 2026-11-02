#!/bin/sh
# Every PHP container starts here (deploy/docker-compose.yml). The settings come from the server (its .env,
# mounted at /app/.env, and the container's environment), so Laravel caches them now rather than in the image.
set -e
php artisan config:cache --no-ansi -q
php artisan route:cache --no-ansi -q
php artisan event:cache --no-ansi -q
exec "$@"
