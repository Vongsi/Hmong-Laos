# Running Daim Av Hlub on the VPS

The live site runs on one VPS (Contabo Cloud VPS, Ubuntu 24.04, Singapore) with Docker. Every push to `main` on
GitHub goes live by itself, and the database and uploads are backed up every night, encrypted, to Google Drive.

| Container | What it does |
| --- | --- |
| `app` | The website, with HTTPS (FrankenPHP: PHP with the Caddy web server, which gets and renews the certificate) |
| `reverb` | Live features: online counts, who is typing, new comments |
| `scheduler` | Timed jobs: Facebook posts, phone notifications, the BCEL catch-up |
| `onepay` | BCEL One payments as they arrive |
| `whatsapp` | Order updates and sign-in codes on WhatsApp (Node.js with Chromium) |
| `mariadb` | The database (not reachable from the internet) |

What outlasts every deploy is kept in Docker volumes: the database (`dah_mariadb`), uploads (`dah_assets`:
photos), `dah_storage` (payment receipts, payout proofs, Facebook and Google sign-in links, caches, logs), the
linked WhatsApp phone (`dah_whatsapp`) and the HTTPS certificate (`dah_caddy_data`). A deploy only replaces the
code.

Files in this folder: `docker-compose.yml` (the containers), `Dockerfile`, `Caddyfile`, `php.ini` and
`entrypoint.sh` (the site's image), `deploy.sh` (puts the latest code live), `backup.sh` (the nightly backup) and
`.env.example` (the server's settings). The WhatsApp sender's image is `whatsapp-sender/Dockerfile`; the GitHub
side is `.github/workflows/deploy.yml`.

**One rule for the team:** blueprints, collections, forms, navigation and globals *settings* are files in the code.
Change them on your computer and push; changes made to them in the live control panel are lost at the next
deploy. Content (posts, events, products, pages, members, orders) is in the database: edit it on the live site
as usual.

## 1. Set up the server (once)

### Order and point the domain

1. Contabo: Cloud VPS, **Ubuntu 24.04**, region **Singapore**, with the **Auto Backup** add-on. Add your SSH key
   when ordering (or set a root password and add the key afterwards).
2. At your domain registrar: an `A` record for the domain (and `www` if you want it) pointing to the VPS's IP
   address. HTTPS starts working once this has spread (minutes to a few hours).

### Secure it and install Docker

Log in as root (`ssh root@<server IP>`) and run:

```bash
apt update && apt -y upgrade
timedatectl set-timezone Asia/Vientiane

# A user for the site, who logs in with the same SSH key as root.
adduser --disabled-password --gecos "" deploy
mkdir -p /home/deploy/.ssh && cp ~/.ssh/authorized_keys /home/deploy/.ssh/
chown -R deploy:deploy /home/deploy/.ssh

# Docker (official installer), usable by the deploy user.
curl -fsSL https://get.docker.com | sh
usermod -aG docker deploy

# Firewall: SSH and the website only.
ufw allow OpenSSH && ufw allow 80/tcp && ufw allow 443 && ufw --force enable

# Where the site lives, and the database copies.
mkdir -p /opt/dah/backups && chown -R deploy:deploy /opt/dah
```

Check that `ssh deploy@<server IP>` works from your computer, then turn off password logins: in
`/etc/ssh/sshd_config` set `PasswordAuthentication no`, and run `systemctl restart ssh`.

### Get the code

As **deploy** (`ssh deploy@<server IP>`):

```bash
ssh-keygen -t ed25519 -N "" -f ~/.ssh/id_ed25519 -C "dah-server"
cat ~/.ssh/id_ed25519.pub
```

On GitHub: repository **Settings → Deploy keys → Add deploy key**, paste that line, leave "Allow write access"
off. Then:

```bash
git clone git@github.com:Vongsi/Hmong-Laos.git /opt/dah/app
```

### Settings

Two files, both only on the server (never committed). Make passwords with `openssl rand -hex 24`.

```bash
cd /opt/dah/app
cp deploy/.env.example deploy/.env && nano deploy/.env    # domain, database, WhatsApp token, backups
cp .env.example .env && nano .env                         # the site's own settings
```

In the site's `.env`, change at least:

| Setting | Value |
| --- | --- |
| `APP_ENV` / `APP_DEBUG` | `production` / `false` |
| `APP_URL` | `https://` + your domain |
| `APP_KEY` | the output of `echo "base64:$(openssl rand -base64 32)"`; never change it afterwards |
| `LOG_STACK` | `daily` (a log file per day, kept 14 days) |
| `SESSION_SECURE_COOKIE` | `true` |
| `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` | new random values |
| `REVERB_HOST` / `REVERB_PORT` / `REVERB_SCHEME` | your domain / `443` / `https` |
| `MAIL_*` | your email provider's details (password resets, order emails) |
| `STATAMIC_LICENSE_KEY` | the Statamic Pro licence |
| `FACEBOOK_*`, `GOOGLE_*`, `ONEPAY_*`, `ANTHROPIC_API_KEY`, `VAPID_*` | as on your computer, or the production ones |

The `DB_*` and `WHATSAPP_SENDER_URL`/`TOKEN` lines in the site's `.env` are ignored: the containers get them from
`deploy/.env`. For the phone-notification keys, make a production pair once (after the first start):
`docker compose exec app php artisan webpush:vapid --show`; never change them afterwards.

### First start

```bash
bash /opt/dah/app/deploy/deploy.sh
```

The first build takes 5–10 minutes. Then make the first control panel account:

```bash
cd /opt/dah/app/deploy
docker compose exec app php please make:user
```

Open `https://<your domain>` and `https://<your domain>/cp`.

### Bring your content from your computer (optional)

To start with what you have locally instead of an empty site: export the database on your computer with
MariaDB's `mariadb-dump` (in your portable MariaDB's `bin` folder), e.g.
`mariadb-dump -h 127.0.0.1 -u root <database> > dah.sql`, and copy it and the uploads up:

```bash
scp dah.sql deploy@<server IP>:/opt/dah/
scp -r public/assets deploy@<server IP>:/opt/dah/upload-assets
scp -r storage/app/private storage/statamic/oauth deploy@<server IP>:/opt/dah/upload-storage/
```

Then on the server:

```bash
cd /opt/dah/app/deploy
docker compose exec -T mariadb sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' < /opt/dah/dah.sql
docker compose cp /opt/dah/upload-assets/. app:/app/public/assets/
docker compose cp /opt/dah/upload-storage/private/. app:/app/storage/app/private/
docker compose cp /opt/dah/upload-storage/oauth/. app:/app/storage/statamic/oauth/
docker compose exec -u root app chown -R www-data:www-data /app/public/assets /app/storage
docker compose exec app php artisan statamic:stache:refresh
docker compose exec app php please search:update --all
rm -r /opt/dah/dah.sql /opt/dah/upload-*
```

### Link WhatsApp, and tell Facebook and Google the new address

- Control panel dashboard → WhatsApp: scan the code with the shop's phone (WhatsApp → Linked devices).
- Facebook app → Facebook Login → Valid OAuth Redirect URIs: `https://<your domain>/oauth/facebook/callback`;
  Google Cloud → Credentials → the OAuth client → Authorised redirect URIs:
  `https://<your domain>/oauth/google/callback`. Both also want the privacy policy (`/privacy`) and data deletion
  (`/delete-my-data`) addresses, and Facebook's app must be switched to **Live** for members to use it.

## 2. Automatic deploys

GitHub Actions (`.github/workflows/deploy.yml`) checks the code after every push to `main`, then logs in to the
server with a key that can do nothing but run `deploy.sh`. Set it up once.

On your computer, make a key pair just for GitHub:

```bash
ssh-keygen -t ed25519 -N "" -f dah-github-actions -C "github-actions"
```

On the server, as deploy, add the **public** key (`dah-github-actions.pub`) to `~/.ssh/authorized_keys`, as one
line starting with this, so the key can only deploy:

```
command="bash /opt/dah/app/deploy/deploy.sh",restrict ssh-ed25519 AAAA… github-actions
```

On GitHub: repository **Settings → Secrets and variables → Actions → New repository secret**:

| Secret | Value |
| --- | --- |
| `DEPLOY_HOST` | the server's IP address |
| `DEPLOY_USER` | `deploy` |
| `DEPLOY_SSH_KEY` | the whole **private** key file `dah-github-actions` |
| `DEPLOY_KNOWN_HOSTS` | the output of `ssh-keyscan -t ed25519 <server IP>` |

Then delete `dah-github-actions` from your computer. From now on, merging a pull request into `main` puts it live
in about 5 minutes; follow it under the repository's **Actions** tab. A commit with `[skip ci]` in its message
doesn't deploy. To deploy by hand: Actions → Deploy → Run workflow, or `bash /opt/dah/app/deploy/deploy.sh` on
the server.

## 3. Backups

Two independent copies:

1. **Contabo Auto Backup** (the add-on): the whole server every day, kept 10 days, restored from Contabo's panel.
2. **`deploy/backup.sh`** every night at 02:30: a clean database copy in `/opt/dah/backups/db` (14 days; Contabo's
   backup takes these along), and an **encrypted** copy of it plus the uploads on Google Drive (database copies 14
   days and one a week for 8 weeks; changed or deleted uploads kept 60 days). Google only ever sees scrambled
   files.

### Google Drive (once)

Use a Google account just for Daim Av Hlub's backups. On the server, as root:

```bash
apt install -y rclone
rclone config
```

1. `n` (new remote), name `gdrive`, storage `drive`. Leave client id and secret empty. Scope: **`drive.file`**
   (it can only see the files it makes). Leave the rest empty, no advanced config.
2. "Use web browser to automatically authenticate?" → `n`. It prints a command like
   `rclone authorize "drive" "…"`. On your computer, download rclone (rclone.org/downloads), run that command in
   its folder, sign in with the backup Google account and allow access, then paste what it prints back into the
   server.
3. `n` again: name `dah-backup`, storage `crypt`, remote `gdrive:daim-av-hlub-backups`, filename and directory
   name encryption on. Let rclone **generate both passwords**, and save both in your password manager straight
   away: without them the backups can't be opened, by anyone.
4. `q` to quit.

Then in `/opt/dah/app/deploy/.env` set `BACKUP_REMOTE=dah-backup:` and try it:

```bash
bash /opt/dah/app/deploy/backup.sh
rclone ls dah-backup:          # readable list; on drive.google.com the names are scrambled
echo '30 2 * * * root bash /opt/dah/app/deploy/backup.sh >> /var/log/dah-backup.log 2>&1' > /etc/cron.d/dah-backup
```

To hear about a failed night: make a free check at healthchecks.io (period 1 day, grace 2 hours) and put its ping
URL in `BACKUP_PING_URL`. It emails you when a backup fails or doesn't happen.

### Restoring

All from `/opt/dah/app/deploy`, as root or deploy.

- **The whole server to an earlier day:** Contabo panel → Auto Backup → restore. Everything since then is lost.
- **The database from a nightly copy** (replaces the current database):
  ```bash
  gunzip -c /opt/dah/backups/db/dah-<date>.sql.gz \
    | docker compose exec -T mariadb sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"'
  ```
- **One deleted thing** (a post, an order) without losing anything else: load the copy into a second database and
  bring back only the rows needed:
  ```bash
  docker compose exec mariadb sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" -e "CREATE DATABASE restore_check"'
  gunzip -c /opt/dah/backups/db/dah-<date>.sql.gz \
    | docker compose exec -T mariadb sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" restore_check'
  ```
- **From Google Drive** (when the server copies are gone): `rclone copy dah-backup:db/daily/<file> /opt/dah/backups/db/`,
  then as above. Uploads: `rclone copy dah-backup:uploads/assets "$(docker volume inspect -f '{{ .Mountpoint }}' dah_assets)"`
  (and `uploads/storage-app`, `uploads/oauth` into `dah_storage`'s `app` and `statamic/oauth`), then
  `docker compose exec -u root app chown -R www-data:www-data /app/public/assets /app/storage`.
- **A new server:** set it up as in part 1, restore the database and uploads from Google Drive, link WhatsApp
  again.

## 4. Everyday

```bash
cd /opt/dah/app/deploy
docker compose ps                         # what's running
docker compose logs -f app                # the website (also reverb, scheduler, onepay, whatsapp, mariadb)
docker compose exec app tail -f storage/logs/laravel-$(date +%F).log
docker compose restart whatsapp           # restart one part
docker compose exec app php please …      # Statamic commands
docker compose exec app php artisan …     # Laravel commands
tail /var/log/dah-backup.log              # last night's backup
```

After changing either `.env`: `docker compose up -d --force-recreate` (the settings are read when the
containers start). Updating Ubuntu now and then: `apt update && apt -y upgrade`, and reboot when it asks; the
containers start again by themselves.
