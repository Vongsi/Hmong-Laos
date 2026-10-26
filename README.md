# Daim Av Hlub

*Daim Av Hlub* means "The Land of Love". Community website for the Hmong in Laos: Xov Xwm (feed with Tshaj tawm announcements), Events, Inspire (interviews), About us with contact, and Community rules. Hmong first, with Lao and English.

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
php artisan db:seed --class=SampleContentSeeder   # sample content (all titled "(sample)") and the site's pages
php please make:user                               # your admin account: answer "yes" to super user
composer dev                                       # web server, Reverb and the scheduler, in one window
```

`composer dev` (`php artisan dev`, set up in `AppServiceProvider`) runs the web server, Reverb (live features) and the scheduler (Facebook and phone-notification jobs) together; Ctrl+C stops them. The web server runs with `--no-reload` so it gets the shell's environment, so restart it after changing `.env`.

**On Windows** with the portable PHP and MariaDB in `%USERPROFILE%\.config`, run `./scripts/dev.ps1` instead. It puts PHP 8.4 first on the PATH, sets `OPENSSL_CONF` (needed to send phone notifications), starts MariaDB if it isn't running, applies new migrations and then runs `php artisan dev`. `./scripts/dev.ps1 -Lan` also serves on your Wi-Fi address so you can open the site on a phone; `./scripts/dev.ps1 -StopDatabase` shuts MariaDB down.

Site: http://127.0.0.1:8000 (Hmong), `/lo/` (Lao), `/en/` (English). Control panel: http://127.0.0.1:8000/cp. On Windows use `127.0.0.1`, not `localhost`: `localhost` tries IPv6 first and every request waits about 2 seconds. Site URLs in `resources/sites.yaml` are relative, so any address works; absolute links follow `APP_URL`, including https in production.

Emails (orders, password resets) are written to `storage/logs/laravel.log` while `MAIL_MAILER=log`; set real mail settings in production.

## Members and sign-in

- **Join** (`/join`): sign in with email and password, or create an account (name, email, password; a hidden honeypot field stops bots). The Facebook and Google buttons only appear once their keys are set (see below). New members start unlisted on the Members page.
- **Forgot your password** (`/forgot-password`) emails a link to **Choose a new password** (`/reset-password`); afterwards the member signs in with the new password. `User::sendPasswordResetNotification()` sends Statamic's reset email, which links to these pages (or to the control panel's own reset page).
- **My account** (`/account`, `app/Livewire/Account.php`): name, province, language for notifications, "Show me in the members list", change password, sign out. Email, roles and other fields stay with the team.
- **Limits:** Statamic's `statamic.auth` rate limit is redefined in `AppServiceProvider`: 30 tries a minute per network address (many phones in Laos share one through their carrier) and 5 sign-ins or reset emails a minute per email address, answered on the form instead of a bare 429 page. It covers the control panel's sign-in too.

## Social login

Facebook and Google work through Statamic OAuth (Laravel Socialite). Create an app with each provider, set the callback to `https://your-domain/oauth/{facebook|google}/callback`, and fill the `FACEBOOK_*` / `GOOGLE_*` keys in `.env`. The buttons appear on `/join` and the control panel login.

Apple and TikTok need the `socialiteproviders/apple` and `socialiteproviders/tiktok` packages; they are not installed yet. Phone number login is also still to do.

## Community features (Livewire)

- `app/Livewire/Comments.php`: comments with one level of replies, 5 per minute per member, hidden automatically after 3 reports, deletable by the author or a super user.
- `app/Livewire/LikeButton.php`: likes on posts and interviews.
- `app/Livewire/EventRsvpBox.php`: Going / Interested and volunteer sign-up on events.
- `app/Livewire/MembersDirectory.php`: the Members page (a page with the `members` template). Everyone sees how many members there are and how many are online; signed-in members also see the list of members who ticked "Show me in the members list" (off by default), with search and a province filter. `app/Http/Middleware/TrackLastSeen.php` keeps `last_seen_at` up to date (at most once a minute), which the control panel shows as "Last seen".

- `public/js/live.js`: live "Name is typing…" line under the comment box, and the comment list refreshes when someone posts. It uses Laravel Reverb (presence channel `comments.{entryId}`, authorised in `routes/channels.php`) and is only loaded for signed-in members. Only members see who is typing, and only members' typing is shown. If Reverb is not running, comments still work without the live parts.
- Also in `public/js/live.js`: every page joins the presence channel `online`, which gives the live "online" count in the header and the green dots on the Members page. The ids on that channel are different from the ones on the comment channels (see `User::getAuthIdentifierForBroadcasting()`), so members who are not listed are counted but can't be identified. Without Reverb, the Members page counts members active in the last 5 minutes instead.

They are used in Antlers templates with `{{ livewire:comments :entry-id="id" }}` and similar.

## Members' posts: news, events and stories ("Share")

- **Share** (`/share`, also linked from My account and from **+ Share news**, **+ Add an event** and **+ Share a story** on the Feed, Events and Inspire pages) lets signed-in members write a news post, an event (date pickers in Laos time, place, map link, phone, entry fee, RSVP and volunteers) or an inspiring story (the person, their photo, and questions with answers) in simple forms with one photo from their phone (`app/Livewire/MemberPosts.php`). Members see everything they sent under **My posts**: *Waiting for review*, *Needs changes* (with the team's message) or *On the website*, and can change or withdraw it until it is published. Up to 6 an hour.
- **Rich text** for the news text and event details: a toolbar (bold, italic, two heading sizes, lists, quote, link) over a TipTap editor (`public/js/rich-text.js`; the editor itself is `public/js/vendor/tiptap-3.32.0.min.js`, loaded only on the Share page). It is saved as Bard content, so the team keeps editing it in the control panel as usual. `app/Support/RichText.php` rebuilds whatever the browser sends with only those formats: no embeds, tables, styles or scripts, and links only to web, email or phone addresses (opened in a new tab, marked nofollow).
- **Photos in the text:** the **Photo** button (or pasting or dropping a photo) adds photos between the paragraphs, up to 12 per post or event. The browser shrinks each photo to 1600 px (JPEG) before uploading it, so phone photos upload quickly; `MemberPosts::storeTextPhoto()` saves it in the member's folder. Only photos uploaded in that form, or already in the text being changed, are kept: images from other websites or other people's photos are dropped. They are saved like photos added in the control panel (`asset::assets::…`), so the team can edit, replace or remove them there.
- **Photo size and position:** tapping a photo in the editor shows a small menu next to it: Small / Medium / Full width, left / centre / right (left and right let the text flow beside the photo; on phones medium photos go full width), move up / move down, and remove. New photos start medium and centred, each in its own paragraph; on a computer they can also be dragged. Size and position are two extra attributes on Bard's image (`size`, `align`): `app/Bard/PhotoLayout.php` reads and renders them on the site (classes `photo-small`, `photo-medium`, `photo-left`, `photo-right`, styled in `public/css/site.css`), and `public/vendor/app/js/cp.js` (loaded in the control panel by `AppServiceProvider`) teaches the control panel's Bard the same attributes so saving there keeps them.
- **In the control panel:** every photo in a Bard field has a **Size** (Small, Medium, Full width) and **Position** (Left, Centre, Right) row at the bottom of its box, under Edit Image and Replace, with the current choice highlighted; selecting a photo also shows the same six choices as icons in the Bard toolbar. Each photo is shown at its size and position while editing (`public/vendor/app/css/cp.css`). Statamic's own Edit Image, Override Alt, Replace and Remove stay as they are. When changing `cp.js` or `cp.css`, raise their `?v=` number in `AppServiceProvider` so browsers load the new file.
- The Share form is wider on big screens (920 px, with a taller editor). Photos taken out of the text, uploads that were not used, and the photos of a withdrawn draft are deleted. (If a member closes the page halfway, the photos uploaded so far stay in their folder.) To upgrade the editor: change the TipTap version in `package.json` (packages and the `build:editor` file name), run `npm install` and `npm run build:editor`, and update the script tag in `resources/views/share/index.antlers.html` (raise its `?v=` number whenever the file changes, so browsers fetch the new one).
- It is saved as a **draft in the language the member is using** (a post written on `/lo/share` is a Lao entry). Translate it by adding the other languages in the control panel ("Working in") before or after publishing. News posts show the member as the author.
- The team gets an email with a link to it. Reviewing works as for products: the **From a member** box shows who sent it; publish it, or write a **Message to the member** and save the draft (`app/Listeners/TrackMemberSubmission.php` emails the member, once per message).
- **Waiting for review** on the control panel dashboard (`app/Widgets/WaitingForReview.php`) lists every member draft (products, news, events, stories) in all languages with a link to it, and separately the ones waiting for the member to make changes. The collection lists show one language at a time, so use this list to find drafts written in Lao or English.
- Member photos are stored in `public/assets/<products|posts|events|inspire>/members/<user id>`; withdrawing a draft deletes the photos the member uploaded.
- **Everything members type is saved without HTML** (`app/Support/PlainText.php`, also applied to profile names in `app/Models/User.php`), because Antlers templates print field values as they are. Keep this for any new field members can fill in.
- **Moderator role** (`resources/users/roles.yaml`): can review, edit and publish products, news posts, events and stories (including other people's posts), manage product categories and colours, upload photos and read form suggestions, but can't see orders, users, pages, globals or settings. Give it to a member under Users → (member) → Roles.

## Sharing to Facebook

- Every page has link-preview tags (Open Graph), so a shared link shows the photo, title and summary. Posts use their photo, events their cover and interviews the portrait; anything else uses `public/images/og-default.png`. Facebook caches previews: after changing a photo, re-scrape the link at https://developers.facebook.com/tools/debug/.
- Posts, events and interviews have Facebook, WhatsApp and Copy link buttons (`resources/views/partials/share.antlers.html`).
- **Auto-post to the Page:** editors tick "Also post to our Facebook Page" in the entry's sidebar. When the entry is live, the site posts its link and title/summary to the Page once (recorded in the `facebook_posts` table). Entries with a future date, or whose post failed, are picked up by `php artisan facebook:post-pending`, which the scheduler runs every 10 minutes, so production needs the usual `* * * * * php artisan schedule:run` cron.
- Setup: create a Meta app, add the Page, request `pages_manage_posts` and `pages_read_engagement` (Meta reviews this), generate a long-lived Page access token, and set `FACEBOOK_PAGE_ID` and `FACEBOOK_PAGE_TOKEN` in `.env`. With them empty, the tick box does nothing and a warning is logged.

## Shop (products, cart, orders)

- **Products** are a collection (`/products`, three languages). Price, stock, sizes, photos, category and province are entered once and shared by every language; only name and texts are translated. Leave "In stock" empty for made-to-order items; 0 shows "Sold out". Tick "Show on the home page" for the home page section.
- **Colours:** a product's "Colours" rows pick from **Taxonomies → Product colours** (name in each language plus a swatch), each with an optional photo of that colour. Buyers must choose one; choosing it shows that photo. The cart, order and team email show the colour. Stock stays per product, counted over all sizes and colours (`app/Shop/PlaceOrder.php`).
- **Photos:** product photos open in a full-screen viewer (whole photo, zoom, swipe, arrow keys) with thumbnails under the gallery (`public/js/site.js`). The same viewer (`photoViewer()` in `public/js/site.js`) opens the photos on news, event and story pages: the main photo (an uncropped large version, `data-full`) and every photo in the text, one after the other; pages mark the area with `data-photos {{ partial:photo_viewer_labels }}`. Upload originals at least 1600 px wide so zooming shows detail.
- **Cart and checkout** are Livewire components (`app/Livewire/AddToCart.php`, `CartPage.php`, `Checkout.php`); the cart lives in the visitor's session and prices are always read from the product, never from the browser. Buyers don't need an account.
- **Orders** are entries in the private `orders` collection (no public URL): the team sees and filters them in the control panel and only changes "Order status" and "Payment"; what the customer entered is read-only. Placing an order takes the items out of stock (`app/Shop/PlaceOrder.php`, one order at a time so the last item can't be sold twice) and emails the address in **Globals → Shop settings**.
- **Payment:** bank transfer / QR (the buyer's order page shows the QR code and bank details from Shop settings; after paying they press "I've paid", which sets the payment to "Customer says paid" and emails the team, who check the bank and set "Paid") or cash on delivery / pick-up. Each customer's order page is at `/order?n=…&t=…`; the secret `t` keeps other people out.
- **Delivery and tracking:** in an order's **Delivery** section the team picks the courier (Anousith Express, another courier or our own delivery), enters the tracking number from the Anousith app and an optional note, then sets the status to "Sent / ready to pick up". Cash orders sent by courier show the **cash to collect (COD)** to enter in the Anousith app. The customer's order page shows a timeline (received → paid → sent → delivered, Laos times), the tracking number with a copy button and a "Track on Anousith Express" button (their public tracking page has a search box, not a link per parcel, so the button also copies the number), and refreshes itself every minute. The first time an order becomes "Sent", `app/Listeners/TrackOrderDelivery.php` emails the customer in their language (if they gave an email) and emails the team a one-tap WhatsApp message for the customer; it never sends twice. Anousith has no public API yet; once they provide one, creating the shipment and reading its status can be added to this listener.
- **Automatic online payment** needs a merchant account with a Lao gateway (for example PhaJay or BCEL OnePay). It would be a third payment method in `Checkout` plus a webhook route that sets the order's `payment_status` to `paid`.
- **"Sell with us"** (`/sell`, also linked from My account): signed-in members add products themselves, with up to 8 photos from their phone, price, stock, sizes, colours, category, province and a phone number only the team sees (`app/Livewire/SellerProducts.php`). A product arrives as a **draft** in Collections → Products (photos in `public/assets/products/members/<user id>`), the team gets an email with a link to it, and the member sees it under "My products" as *Waiting for review*. Members can change or withdraw it until it is published, never after.
- **Reviewing:** the product's **From a member** box (top of the sidebar, only on products sent by members) shows who sent it. Check the photos, price and texts, translate them if needed, then switch Published on and save: the Lao and English versions are published too and the member gets an email in their language that it is in the shop. To ask for changes instead, write a **Message to the seller** and save it as a draft: the member gets it by email and on their page (*Needs changes*), updates the product and sends it again, which clears the message. Each message and the "in the shop" email go out only once (`app/Listeners/TrackMemberSubmission.php`). Members can send up to 6 products an hour.
- Older suggestions sent through the previous "Sell with us" form are still under Forms → Suggest a product.

## Background photo and seasons

**Globals → Appearance** sets the photo behind every page: a default one, how visible it is (Subtle / Medium / Strong; all three keep text at WCAG AA contrast), and seasonal photos with a first and last day (Laos dates) that replace the default while they run, so a New Year background can be prepared weeks ahead. `app/Appearance/Background.php` picks the photo; the layout serves a 1920×1200 version to wide screens and an 800×1400 portrait crop to phones, both around the photo's focal point. Replacing a photo changes its URL, so phones with the offline cache get the new one.

## Home page pop-up

**Globals → Home page pop-up** shows an important announcement or event in a pop-up when visitors open the home page. Pick a post, event, interview or product and the pop-up uses its title, photo, summary (and date and venue for events) in each visitor's language, with a button to it; title, message, photo, button text and link can be overridden, and optional first and last days (Laos dates) schedule it. Visitors close it with ×, the Close button, Escape or a click outside; it then stays closed on that device in every language, until the team changes the pop-up. Code: `app/Appearance/HomePopup.php` (what to show), `resources/views/partials/home_popup.antlers.html` (a native `<dialog>`), and the end of `public/js/site.js` (opening and remembering).

## Phone app (installable website)

The site can be installed on a phone's home screen and opens like an app, with no app store needed. Visitors see a "Get … on your phone" box on the home page.

- **Install:** Android and desktop Chrome/Edge show an Install button. On iPhone the box explains Safari's Share → "Add to Home Screen". Each language has its own manifest (`/manifest/{hm|lo|en}.webmanifest`, `app/Http/Controllers/PwaController.php`), so the app opens in the language it was installed from. Icons are in `public/icons`.
- **Offline reading:** `public/sw.js` keeps the last 40 pages and 80 photos a visitor opened, so they can still be read without internet. Pages always load fresh when online. Unvisited pages show `public/offline.html`. The control panel, Livewire, login and push routes are never cached. Bump `VERSION` in `sw.js` when you change it.
- **Notifications:** signed-in members tap "Turn on notifications". Editors tick "Send a phone notification" in the sidebar of a post, event or interview; once it is live, members who turned notifications on and whose site language matches the entry's get one notification (members with no language set count as Hmong). Each entry is sent once, recorded in `push_broadcasts`. Entries with a future date are sent by `php artisan push:send-pending`, which the scheduler runs every 10 minutes. On iPhone (iOS 16.4+) notifications only work in the installed app.
- **Setup:** run `php artisan webpush:vapid` once and copy `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY` and `VAPID_SUBJECT` (a `mailto:` address) into the production `.env`. Never change the keys afterwards. The site must be served over HTTPS for install and notifications to work.

## Dates and times

Dates are saved in UTC (the control panel converts from the editor's own clock) and shown on the site in Laos time (`display_timezone` in `config/statamic/system.php`, `STATAMIC_DISPLAY_TIMEZONE` to change it). Code that formats a date itself must convert too, as `app/Appearance/HomePopup.php` and `app/Livewire/OrderStatus.php` do.

## Languages

UI strings are in `lang/hmn`, `lang/lo` and `lang/en`. The Hmong and Lao files only contain wording agreed so far; missing strings fall back to English. Hmong wording should be checked by the team, not machine-generated.

**Content in other languages.** News, events, stories and pages are written per language (products get all three automatically). To copy an entry to the other languages:
- **Copy to other languages** (`app/Actions/CopyToLanguages.php`): in an entry's ⋯ menu, or for several entries at once by ticking them in the list. Pick the languages (the missing ones are ticked) and whether to publish the copies now (off by default, so they can be translated first; copies of a draft stay drafts).
- Or one at a time with Statamic's **Working in** panel: click a crossed-out language to create that version.

Each copy starts with the same text and photos and keeps following the entry it was copied from until a field is changed in that language; translate it by opening that language under Working in. When a member's draft is published, its copies are published with it. The collection lists show one language at a time: switch with the site menu at the top or the **Site** filter.

## Production notes

- Statamic Pro licence is required (multisite, users, OAuth). Buy it at statamic.com and set `STATAMIC_LICENSE_KEY`.
- PHP must accept phone photos: set `upload_max_filesize` and `post_max_size` to at least `64M`, because members can pick several photos at once (Forge: PHP → Max File Upload Size). Nginx needs the same `client_max_body_size`.
- Planned hosting: Singapore region. Use `CACHE_STORE=redis` or `database`, and run `php artisan optimize` and `php please stache:warm` on deploy.
- Run Reverb as a long-lived process (Supervisor or Forge daemon) behind the web server with TLS; set `REVERB_HOST` to the public host and `REVERB_SCHEME=https`, `REVERB_PORT=443`. See https://laravel.com/docs/reverb#production.
- Chat (planned for a later phase) can reuse the same Reverb setup.
