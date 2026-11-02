#!/usr/bin/env bash
# Puts the latest code on GitHub's main branch live (deploy/README.md). GitHub Actions runs it after each push;
# it also works by hand on the server, as the deploy user: /opt/dah/app/deploy/deploy.sh
set -euo pipefail

# All in a function: bash reads a script as it goes, and this one is replaced by `git reset` halfway through.
main() {
    cd "$(dirname "$(readlink -f "$0")")"

    # One deploy at a time.
    exec 9>/tmp/dah-deploy.lock
    flock 9

    [ -f .env ] || { echo "deploy/.env is missing: copy deploy/.env.example and fill it in." >&2; exit 1; }
    [ -f ../.env ] || { echo "The site's .env is missing in $(cd .. && pwd)." >&2; exit 1; }

    echo "→ Getting the latest code"
    git -C .. fetch --quiet origin main
    git -C .. reset --hard --quiet origin/main
    echo "  $(git -C .. log -1 --format='%h %s')"

    echo "→ Building"
    docker compose build --pull --quiet

    echo "→ Database changes"
    docker compose run --rm app php artisan migrate --force --no-ansi

    echo "→ Starting the new version"
    docker compose up -d --remove-orphans
    # Blueprints, collections and the like are files in the code: let Statamic read the new ones.
    docker compose exec -T app php artisan view:clear --no-ansi -q
    docker compose exec -T app php artisan statamic:stache:refresh --no-ansi

    docker image prune -f >/dev/null
    echo "✓ Live: https://$(grep -E '^SERVER_NAME=' .env | cut -d= -f2)"
}

main "$@"
