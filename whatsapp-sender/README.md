# WhatsApp sender

Sends the shop's order updates to buyers on WhatsApp, from the shop's own number: order received, confirmed, payment received, payment not accepted, sent / ready to pick up, completed, cancelled. The website decides what to send and in which language (`app/Shop/WhatsApp.php`, `app/Listeners/SendOrderWhatsApp.php`); this small Node.js program only sends.

It uses [whatsapp-web.js](https://wwebjs.dev): WhatsApp Web runs in a hidden Chrome, linked to the shop's phone the way WhatsApp on a computer is. There is no per-message fee, unlike the official WhatsApp Business (Cloud) API.

## Read this first: the risk

WhatsApp's terms don't allow unofficial clients like this one for businesses, and WhatsApp can block a number that looks automated or gets reported as spam. To keep the risk low:

- Use a **number only for the shop** (a separate SIM), never someone's personal WhatsApp. If it is blocked, the shop loses only that number.
- Send **only order updates to people who ordered**, never adverts or messages to lists. Buyers who expect a message don't report it.
- Keep the pace: one message at a time, 8 to 15 seconds apart, at most 200 a day (all adjustable, see Settings).
- Keep the phone switched on and connected now and then: WhatsApp unlinks computers whose phone has been offline for about 14 days.

If the number is blocked, or the sender stops, nothing is lost: the team still gets every update by email with a ready-made "Send the customer a WhatsApp message" button, and the order shows which messages didn't go.

If the shop grows, the official Cloud API is the safe route (paid per message; utility messages to Laos cost about US$0.013 each).

## How it works

```
website ──POST /messages──▶ sender ──(queue, one by one)──▶ WhatsApp Web ──▶ buyer
website ◀──POST /whatsapp/status (sent / failed) ── sender
```

- It listens on `127.0.0.1` only, and every request must carry the shared token (`Authorization: Bearer …`). It answers at once and sends later, so the website never waits for WhatsApp.
- Waiting messages and the latest 300 results are kept in `data/` (`queue.json`, `history.json`), so a restart loses nothing. The full log, with every message's text, is on the website: control panel → Collections → WhatsApp messages. The linked session is in `data/session/`. `data/` is private: don't copy it around, and don't commit it.
- Before sending, it checks the number is on WhatsApp ("This number is not on WhatsApp" otherwise). A message that fails 3 times, or is still waiting after 12 hours (phone not linked, daily limit), is reported as failed, and the team is emailed with the message ready to send by hand.
- After sending, it follows the message's ticks (WhatsApp's acknowledgements) and reports **delivered** (two grey ticks: on the buyer's phone) and **read** (two blue ticks), with the time. Read only shows when the buyer has read receipts on, and the shop's WhatsApp must keep its own read receipts on (WhatsApp hides others' when yours are off). Ticks are read straight from WhatsApp Web: WhatsApp has been moving chats to new ids ("LID"), and whatsapp-web.js 1.34.7 then neither hands back the sent message nor gives its tick events a message id, so the sender finds the message itself (by text and time) and follows its ticks. Ticks that changed while the sender was off are picked up after it reconnects, and every 2 minutes, for the last 3 days' messages. In a dry run every message is "delivered" after 1.5 seconds and "read" after 3.
- It doesn't read or answer incoming messages: buyers who reply reach the shop's phone as usual, and the team answers from there.

## Run it

You need Node.js 20.12 or newer.

```bash
cd whatsapp-sender
npm install          # on the server: npm ci
```

In the website's `.env`:

```
WHATSAPP_SENDER_URL=http://127.0.0.1:3100
WHATSAPP_SENDER_TOKEN=<a long random text: php -r "echo bin2hex(random_bytes(24));">
```

Then, from the website's folder:

```bash
php artisan whatsapp:sender            # runs server.js with the website's settings
php artisan whatsapp:sender --dry-run  # nothing goes to WhatsApp; every message is marked sent
```

`php artisan dev` starts it by itself once both settings are set and `npm install` was run here.

### Link the shop's phone

Open the control panel's dashboard: the box **WhatsApp updates to buyers** shows a QR code. On the shop's phone open WhatsApp → Settings → Linked devices → Link a device, and scan it. The box then says which number is linked; send yourself a test from there. **Unlink** in the same box (or removing the device on the phone) unlinks it, and a new code appears.

Shop settings → **WhatsApp updates to buyers** turns it off for everyone, and **Send a WhatsApp message when** chooses which updates go.

## On the server (Forge, Ubuntu)

1. Node.js 20.12+ (Forge installs Node; check `node -v`).
2. Chrome and its system libraries. The easiest: install Google Chrome from Google's package (its own sandbox works on Ubuntu 24.04) and use it:
   ```bash
   wget https://dl.google.com/linux/direct/google-chrome-stable_current_amd64.deb
   sudo apt install ./google-chrome-stable_current_amd64.deb
   ```
   and in the website's `.env`: `CHROME_PATH=/usr/bin/google-chrome`.
   Or keep the Chrome that Puppeteer downloads and install only its libraries, once, as root: `npx puppeteer browsers install chrome --install-deps`. On Ubuntu 23.10 and later that Chrome usually can't use its sandbox ("No usable sandbox"); then either use Google Chrome as above or set `CHROME_NO_SANDBOX=true` (it only opens web.whatsapp.com, but the sandbox is an extra wall around what it shows, so prefer Google Chrome).
3. Deploy script: `cd whatsapp-sender && npm ci --omit=dev`.
4. Website `.env`: `WHATSAPP_SENDER_URL` and `WHATSAPP_SENDER_TOKEN` as above. With zero-downtime deploys (a new folder for each release), also `WHATSAPP_SENDER_DATA_DIR=/home/forge/whatsapp-sender-data`, so the linked phone survives a deploy; otherwise each deploy asks for the code again.
5. Forge → Daemons: command `php artisan whatsapp:sender`, directory the site's folder, user `forge`. Restart the daemon after deploying a new version.
6. Link the phone from the dashboard.

Chrome needs about 300 to 500 MB of memory.

## Settings

`php artisan whatsapp:sender` passes on everything in the website's `.env`, so set them there (it is shared between releases). `whatsapp-sender/.env` (see `.env.example`) is for running `node server.js` by itself; the website's values win.

| Setting | Default | |
|---|---|---|
| `TOKEN` | (from `WHATSAPP_SENDER_TOKEN`) | Shared secret, at least 24 characters. |
| `HOST`, `PORT` | `127.0.0.1`, `3100` | Where it listens (port from `WHATSAPP_SENDER_URL`). |
| `CALLBACK_URL` | (the website's `/whatsapp/status`) | Where it reports what happened. |
| `DATA_DIR` | `data` (or `WHATSAPP_SENDER_DATA_DIR`) | Session, queue and history. On Windows keep it short. |
| `GAP_SECONDS`, `JITTER_SECONDS` | `8`, `7` | Pause between messages: 8 seconds plus up to 7 more. |
| `DAILY_LIMIT` | `200` | Most messages a day (Laos time). |
| `MAX_AGE_HOURS` | `12` | Drop messages still waiting after this long. |
| `STALL_MINUTES` | `3` | Starting or loading without progress this long: start again (the link is kept). |
| `DRY_RUN` | `false` | Pretend to send. |
| `CHROME_PATH` | Puppeteer's Chrome | Another Chrome. |
| `CHROME_NO_SANDBOX` | `false` | Only if Chrome can't start otherwise. |
| `WA_WEB_VERSION` | (latest) | Pin a WhatsApp Web version. |

## The API (for the website)

All with `Authorization: Bearer <TOKEN>`.

- `GET /status`: `status` (`starting`, `qr`, `ready`, `disconnected`), `me` (the linked number), `qr` (the code as a PNG data URL), `queued`, `sent_today`, `daily_limit`, `recent` (the last 10 results).
- `POST /messages` `{ "id": "…", "to": "8562055551234", "text": "…", "ref": "<order id>" }`: queued (202). The same `id` again changes nothing. `to` is the number with its country code, digits only.
- `POST /logout`: unlinks the number.

It reports each step to `CALLBACK_URL` as `{ id, ref, to, status: "sent" | "delivered" | "read" | "failed", error, at, wa_id, queued_at, done_at, delivered_at, read_at }` (`at`: when that step happened), trying again a few times if the website doesn't answer. The website only moves a message forward, so steps arriving late or twice change nothing.

## When something goes wrong

What the sender did is also written to `data/sender.log` (the last 1 MB or so; the one before in `sender.log.old`).

- **The dashboard says the sender isn't running**: start it (`php artisan whatsapp:sender`, or check the Forge daemon's log).
- **"Could not open WhatsApp Web: … Execution context was destroyed"** on every start:
  - on Windows, a long `DATA_DIR` is the usual cause (Chrome can't keep WhatsApp's keys there and WhatsApp Web resets itself); use a short folder such as `C:\wa-sender`.
  - otherwise a WhatsApp Web update may have broken whatsapp-web.js: update it (`npm update whatsapp-web.js`, then restart), or pin a version that works with `WA_WEB_VERSION` (versions: github.com/wppconnect-team/wa-version).
- **The dashboard stays on "Opening WhatsApp…" or "Linked. WhatsApp is loading…"** and the sender starts again every few minutes: the installed whatsapp-web.js doesn't fit the current WhatsApp Web (it never says "ready"). Update it as above. Don't let `npm audit fix --force` downgrade it: it swaps in an old version (1.34.2, with a 2022 Chromium) to quiet the install-time warnings below, and that version can't finish loading today's WhatsApp Web.
- **It keeps asking for the code** after being linked: the phone unlinked it (or was offline for weeks). Scan again.
- **Messages "not sent within 12 hours"**: the phone wasn't linked, or the daily limit was reached.
- `npm audit` lists warnings in Puppeteer's download tools (FTP, proxy and zip handling used when installing Chrome); they aren't used while sending.
