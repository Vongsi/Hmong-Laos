# Hmong Laos

Community website for the Hmong in Laos: Xov Xwm (feed with Tshaj tawm announcements), Events, Inspire (interviews), About us with contact, and Community rules. Hmong first, with Lao and English.

Built with Statamic 6 (Pro) on Laravel 13, Livewire 4 and MySQL/MariaDB.

## What lives where

| In MySQL (changes every day) | In files, tracked in git (site structure) |
| --- | --- |
| Entries (posts, events, interviews, pages) | Collection and taxonomy definitions (`content/collections`, `content/taxonomies`) |
| Taxonomy terms, global values, navigation trees | Blueprints and fieldsets (`resources/blueprints`) |
| Users, password resets, passkeys | Sites / languages (`resources/sites.yaml`), role and group definitions (`resources/users`) |
| Form submissions, revisions, asset metadata | Form definitions (`resources/forms`) |
| Comments, likes, event RSVPs, comment reports | Templates, CSS, JS, translations (`resources/views`, `public`, `lang`) |

Everything editors and members create goes to the database. Only the structure stays in files, so it can be reviewed and deployed through git like code. If you ever want the structure in the database as well, set the remaining keys in `config/statamic/eloquent-driver.php` to `eloquent` and run `php please eloquent:import-*`.

## Requirements

- PHP 8.3+ with `pdo_mysql`, `intl`, `gd` or `imagick`, `exif`, `bcmath`
- Composer 2
- MySQL 8 or MariaDB 10.6+

## Local setup

```bash
composer install
cp .env.example .env
php artisan key:generate

# create the database
mysql -u root -e "CREATE DATABASE hmong_laos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER 'hmong'@'localhost' IDENTIFIED BY 'secret';
  GRANT ALL ON hmong_laos.* TO 'hmong'@'localhost';"
# then set DB_PASSWORD=secret in .env

php artisan migrate
php artisan db:seed --class=SampleContentSeeder   # optional sample content, all titled "(sample)"
php please make:user                               # answer "yes" to super user
php artisan serve
php artisan reverb:start   # in a second terminal, for the typing indicator
```

Site: http://localhost:8000 (Hmong), `/lo/` (Lao), `/en/` (English). Control panel: http://localhost:8000/cp.

## Social login

Facebook and Google work through Statamic OAuth (Laravel Socialite). Create an app with each provider, set the callback to `https://your-domain/oauth/{facebook|google}/callback`, and fill the `FACEBOOK_*` / `GOOGLE_*` keys in `.env`. The buttons appear on `/join` and the control panel login.

Apple and TikTok need the `socialiteproviders/apple` and `socialiteproviders/tiktok` packages; they are not installed yet. Phone number login is also still to do.

## Community features (Livewire)

- `app/Livewire/Comments.php`: comments with one level of replies, 5 per minute per member, hidden automatically after 3 reports, deletable by the author or a super user.
- `app/Livewire/LikeButton.php`: likes on posts and interviews.
- `app/Livewire/EventRsvpBox.php`: Going / Interested and volunteer sign-up on events.

- `public/js/live.js`: live "Name is typing…" line under the comment box, and the comment list refreshes when someone posts. It uses Laravel Reverb (presence channel `comments.{entryId}`, authorised in `routes/channels.php`) and is only loaded for signed-in members. Only members see who is typing, and only members' typing is shown. If Reverb is not running, comments still work without the live parts.

They are used in Antlers templates with `{{ livewire:comments :entry-id="id" }}` and similar.

## Sharing to Facebook

- Every page has link-preview tags (Open Graph), so a shared link shows the photo, title and summary. Posts use their photo, events their cover and interviews the portrait; anything else uses `public/images/og-default.png`. Facebook caches previews: after changing a photo, re-scrape the link at https://developers.facebook.com/tools/debug/.
- Posts, events and interviews have Facebook, WhatsApp and Copy link buttons (`resources/views/partials/share.antlers.html`).
- **Auto-post to the Page:** editors tick "Also post to our Facebook Page" in the entry's sidebar. When the entry is live, the site posts its link and title/summary to the Page once (recorded in the `facebook_posts` table). Entries with a future date, or whose post failed, are picked up by `php artisan facebook:post-pending`, which the scheduler runs every 10 minutes, so production needs the usual `* * * * * php artisan schedule:run` cron.
- Setup: create a Meta app, add the Page, request `pages_manage_posts` and `pages_read_engagement` (Meta reviews this), generate a long-lived Page access token, and set `FACEBOOK_PAGE_ID` and `FACEBOOK_PAGE_TOKEN` in `.env`. With them empty, the tick box does nothing and a warning is logged.

## Languages

UI strings are in `lang/hmn`, `lang/lo` and `lang/en`. The Hmong and Lao files only contain wording agreed so far; missing strings fall back to English. Hmong wording should be checked by the team, not machine-generated.

## Production notes

- Statamic Pro licence is required (multisite, users, OAuth). Buy it at statamic.com and set `STATAMIC_LICENSE_KEY`.
- Planned hosting: Singapore region. Use `CACHE_STORE=redis` or `database`, and run `php artisan optimize` and `php please stache:warm` on deploy.
- Run Reverb as a long-lived process (Supervisor or Forge daemon) behind the web server with TLS; set `REVERB_HOST` to the public host and `REVERB_SCHEME=https`, `REVERB_PORT=443`. See https://laravel.com/docs/reverb#production.
- Chat (planned for a later phase) can reuse the same Reverb setup.
