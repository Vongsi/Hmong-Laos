#!/usr/bin/env bash
# Nightly backup (deploy/README.md → Backups), run by cron as root.
#  1. A clean copy of the database in /opt/dah/backups/db, kept 14 days. Contabo's Auto Backup takes these along,
#     and they let you bring back one deleted item without rolling the whole server back.
#  2. When BACKUP_REMOTE is set (an encrypted rclone remote on Google Drive): that copy goes there too, kept
#     14 days, with Sunday's kept 8 weeks; and the uploads (photos, payment receipts, payout proofs, Facebook and
#     Google sign-in links) are synced there, keeping changed or deleted files for 60 days.
set -euo pipefail

main() {
    cd "$(dirname "$(readlink -f "$0")")"
    set -a; . ./.env; set +a

    local dir="${BACKUP_DIR:-/opt/dah/backups}"
    local stamp file
    stamp="$(date +%F-%H%M)"
    file="$dir/db/dah-$stamp.sql.gz"
    mkdir -p "$dir/db"
    trap 'rm -f "$file.part"; report fail' ERR

    # 1. The database, consistent without stopping the site.
    docker compose exec -T -e MYSQL_PWD="$DB_ROOT_PASSWORD" mariadb \
        mariadb-dump -uroot --single-transaction --routines --triggers --no-tablespaces \
        --default-character-set=utf8mb4 "$DB_DATABASE" | gzip > "$file.part"
    mv "$file.part" "$file"
    find "$dir/db" -name 'dah-*.sql.gz' -mtime +14 -delete
    echo "$(date '+%F %T') database: $file ($(du -h "$file" | cut -f1))"

    # 2. Off the server, encrypted.
    if [ -n "${BACKUP_REMOTE:-}" ]; then
        local remote="${BACKUP_REMOTE%/}" old="${BACKUP_REMOTE%/}/uploads/old/$stamp"
        rclone copy "$file" "$remote/db/daily"
        if [ "$(date +%u)" = 7 ]; then
            rclone copy "$file" "$remote/db/weekly"
        fi
        rclone delete "$remote/db/daily" --min-age 15d
        rclone delete "$remote/db/weekly" --min-age 57d

        local assets storage
        assets="$(docker volume inspect -f '{{ .Mountpoint }}' dah_assets)"
        storage="$(docker volume inspect -f '{{ .Mountpoint }}' dah_storage)"
        rclone sync "$assets" "$remote/uploads/assets" --backup-dir "$old/assets"
        rclone sync "$storage/app" "$remote/uploads/storage-app" --backup-dir "$old/storage-app" --exclude 'private/livewire-tmp/**'
        rclone sync "$storage/statamic/oauth" "$remote/uploads/oauth" --backup-dir "$old/oauth"
        rclone delete "$remote/uploads/old" --min-age 60d
        rclone rmdirs "$remote/uploads/old" --leave-root
        echo "$(date '+%F %T') copied to $remote"
    fi

    report
}

# Tells healthchecks.io (if set up) that tonight's backup worked, or that it failed.
report() {
    [ -n "${BACKUP_PING_URL:-}" ] || return 0
    curl -fsS -m 10 --retry 3 "${BACKUP_PING_URL%/}${1:+/$1}" >/dev/null || true
}

main "$@"
