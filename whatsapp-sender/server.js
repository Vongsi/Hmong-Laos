// Daim Av Hlub WhatsApp sender: sends the shop's order updates to buyers from the shop's own WhatsApp number,
// through WhatsApp Web (whatsapp-web.js) in a hidden browser. The website (App\Shop\WhatsApp) hands it messages;
// it sends them one at a time with a pause in between, keeps them across restarts, and tells the website what
// happened (CALLBACK_URL): sent, then delivered and read as the ticks come in, or failed and why. Private: it
// listens on 127.0.0.1 and every request needs the shared TOKEN.
// Not an official WhatsApp product: keep the volume low and only message people who ordered. See README.md.

import crypto from 'node:crypto';
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import QRCode from 'qrcode';
import wwebjs from 'whatsapp-web.js';

const { Client, LocalAuth } = wwebjs;

const here = path.dirname(fileURLToPath(import.meta.url));
if (fs.existsSync(path.join(here, '.env'))) {
    process.loadEnvFile(path.join(here, '.env'));
}

const config = {
    host: process.env.HOST || '127.0.0.1',
    port: Number(process.env.PORT || 3100),
    token: process.env.TOKEN || '',
    callbackUrl: process.env.CALLBACK_URL || '',
    dataDir: path.resolve(here, process.env.DATA_DIR || 'data'),
    // At least this many seconds between two messages, plus up to JITTER_SECONDS more, so it doesn't look like a bot.
    gapSeconds: Number(process.env.GAP_SECONDS || 8),
    jitterSeconds: Number(process.env.JITTER_SECONDS || 7),
    // At most this many messages a day (Laos time); the rest wait for the next day, or expire.
    dailyLimit: Number(process.env.DAILY_LIMIT || 200),
    // A message still waiting after this many hours (WhatsApp not linked, daily limit) is dropped: too late to help.
    maxAgeHours: Number(process.env.MAX_AGE_HOURS || 12),
    // true: don't open WhatsApp at all, just pretend each message was sent (for testing the website).
    dryRun: process.env.DRY_RUN === 'true',
    chromePath: process.env.CHROME_PATH || undefined,
    // Only if Chrome can't start on the server (Ubuntu 24.04 blocks its sandbox for downloaded Chrome); see README.md.
    noSandbox: process.env.CHROME_NO_SANDBOX === 'true',
    // Pin a WhatsApp Web version when a WhatsApp update breaks whatsapp-web.js (see README.md).
    webVersion: process.env.WA_WEB_VERSION || '',
    // Starting or loading without progress for this long: start again (the link is kept).
    stallMinutes: Number(process.env.STALL_MINUTES || 3),
};

if (config.token.length < 24) {
    console.error('Set TOKEN (at least 24 characters, the same as WHATSAPP_SENDER_TOKEN in the website\'s .env).');
    process.exit(1);
}

fs.mkdirSync(config.dataDir, { recursive: true });

// Also kept in data/sender.log (the last 1 MB or so), to look back at what happened.
const logFile = path.join(config.dataDir, 'sender.log');
const log = (...args) => {
    const line = [new Date().toISOString(), ...args].join(' ');
    console.log(line);
    try {
        if (fs.existsSync(logFile) && fs.statSync(logFile).size > 1_000_000) {
            fs.renameSync(logFile, `${logFile}.old`);
        }
        fs.appendFileSync(logFile, `${line}\n`);
    } catch {
        // the console has it
    }
};
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const laosDay = (date = new Date()) => date.toLocaleDateString('en-CA', { timeZone: 'Asia/Vientiane' });

// The queue (waiting messages) and the history (the last messages handled) are kept in data/, so a restart loses nothing.
const files = { queue: path.join(config.dataDir, 'queue.json'), history: path.join(config.dataDir, 'history.json') };
const read = (file) => {
    try {
        return JSON.parse(fs.readFileSync(file, 'utf8'));
    } catch {
        return [];
    }
};
const write = (file, value) => {
    fs.writeFileSync(`${file}.tmp`, JSON.stringify(value, null, 1));
    fs.renameSync(`${file}.tmp`, file);
};
let queue = read(files.queue);
let history = read(files.history);
const persist = () => {
    write(files.queue, queue);
    write(files.history, history);
};

// status: starting → qr (waiting for the phone) → loading (linked, WhatsApp Web loading; progress in %) → ready.
const state = { status: config.dryRun ? 'ready' : 'starting', qr: null, me: null, error: null, progress: null, pausedUntil: null };

// ---------------------------------------------------------------------------------------------------------------
// WhatsApp

let client = null;

// Watchdog: whatsapp-web.js can wait forever when WhatsApp Web changed under it (it never says "ready"). If starting
// or loading makes no progress for STALL_MINUTES, start again; the link is kept, so no new code is needed.
let stallTimer = null;
function watch() {
    clearTimeout(stallTimer);
    stallTimer = setTimeout(() => {
        if (['ready', 'qr'].includes(state.status) || restarting) {
            return;
        }
        state.error = `WhatsApp didn't finish ${state.status === 'loading' ? 'loading' : 'starting'} within ${config.stallMinutes} minutes, so it was started again. If this keeps happening, update whatsapp-web.js (README: When something goes wrong).`;
        log(state.error);
        restart(5_000);
    }, config.stallMinutes * 60_000);
}

function start() {
    if (config.dryRun) {
        state.me = { number: 'dry-run', name: 'Dry run (nothing is sent)' };
        log('DRY_RUN: messages are marked sent without opening WhatsApp.');

        return;
    }

    state.status = 'starting';
    state.progress = null;
    ticksExposed = false;
    watch();
    client = new Client({
        authStrategy: new LocalAuth({ dataPath: path.join(config.dataDir, 'session') }),
        puppeteer: {
            headless: true,
            executablePath: config.chromePath,
            args: config.noSandbox ? ['--no-sandbox', '--disable-setuid-sandbox'] : [],
        },
        webVersionCache: config.webVersion
            ? { type: 'remote', remotePath: 'https://raw.githubusercontent.com/wppconnect-team/wa-version/main/html/{version}.html' }
            : { type: 'local', path: path.join(config.dataDir, 'web-cache') },
        ...(config.webVersion ? { webVersion: config.webVersion } : {}),
    });

    client.on('qr', async (qr) => {
        state.status = 'qr';
        state.qr = await QRCode.toDataURL(qr, { margin: 1, width: 264 });
        log('Not linked: open the website\'s dashboard (WhatsApp updates) and scan the code with the shop\'s phone.');
    });
    client.on('authenticated', () => {
        state.status = 'loading';
        state.qr = null;
        log('Linked. Loading WhatsApp…');
        watch();
    });
    client.on('loading_screen', (percent) => {
        state.progress = Number(percent) || 0;
        if (state.status !== 'ready') {
            state.status = 'loading';
        }
        watch();
    });
    client.on('auth_failure', (message) => {
        state.status = 'disconnected';
        state.error = `Linking failed: ${message}`;
        log(state.error);
    });
    client.on('ready', () => {
        clearTimeout(stallTimer);
        state.status = 'ready';
        state.qr = null;
        state.error = null;
        state.progress = null;
        state.me = { number: client.info?.wid?.user ?? null, name: client.info?.pushname ?? null };
        log(`Linked as +${state.me.number} (${state.me.name}). ${queue.length} message(s) waiting.`);
        work();
        // Ticks that changed while it was off, once WhatsApp Web has caught up.
        setTimeout(recheck, 20_000);
        hookTicks();
    });
    // Delivered (two grey ticks) and read (two blue ticks) for the messages it sent. With current WhatsApp Web this
    // event has no message id (see hookTicks), so this only helps once whatsapp-web.js catches up.
    client.on('message_ack', (message, ack) => {
        if (message?.fromMe && message.id?._serialized) {
            ticked(message.id._serialized, ack);
        }
    });
    client.on('disconnected', (reason) => {
        state.status = 'disconnected';
        state.me = null;
        state.error = `Disconnected: ${reason}`;
        log(`${state.error}. Starting again for a new code.`);
        restart(5_000);
    });

    client.initialize().catch((error) => {
        // Closed on purpose (the watchdog, unlinking): starting again already.
        if (restarting) {
            return;
        }
        state.status = 'disconnected';
        state.error = `Could not open WhatsApp Web: ${error.message}`;
        // Seen on Windows: in a long folder path Chrome can't keep WhatsApp's keys, and WhatsApp Web resets itself.
        if (process.platform === 'win32' && config.dataDir.length > 80 && /context was destroyed/i.test(error.message)) {
            state.error += ` On Windows keep DATA_DIR short (now ${config.dataDir.length} characters), for example C:\\wa-sender.`;
        }
        log(state.error);
        restart(60_000);
    });
}

let restarting = false;
async function restart(delay) {
    if (restarting) {
        return;
    }
    restarting = true;
    try {
        await client?.destroy();
    } catch {
        // already closed
    }
    client = null;
    await sleep(delay);
    restarting = false;
    start();
}

// ---------------------------------------------------------------------------------------------------------------
// Sending, one at a time

let working = false;
let nextAt = 0;

const sentToday = () => history.filter((item) => item.status === 'sent' && laosDay(new Date(item.done_at)) === laosDay()).length;

// wa_id: WhatsApp's own id for the message, to follow its ticks.
function finish(message, status, error = null, waId = null) {
    queue = queue.filter((item) => item.id !== message.id);
    const now = new Date().toISOString();
    const item = { id: message.id, ref: message.ref, to: message.to, status, error, wa_id: waId, queued_at: message.queued_at, done_at: now, delivered_at: null, read_at: null };
    history = [...history, item].slice(-300);
    persist();
    log(`${message.id} → +${message.to}: ${status}${error ? ` (${error})` : ''}`);
    callback({ ...item, at: now });
}

// WhatsApp's acknowledgements: 2 = on the buyer's phone (delivered), 3 or more = read (shown only when the buyer
// has read receipts on, and the shop's WhatsApp too), -1 = WhatsApp couldn't deliver it. Only forward, once each.
function ticked(waId, ack) {
    const item = waId ? history.find((entry) => entry.wa_id === waId) : null;
    if (!item || item.status !== 'sent') {
        return;
    }
    const now = new Date().toISOString();
    if (ack === -1 && !item.delivered_at) {
        item.status = 'failed';
        item.error = 'WhatsApp could not deliver it';
        persist();
        log(`${item.id}: ${item.error}`);
        callback({ ...item, at: now });

        return;
    }
    const reached = [];
    if (ack >= 2 && !item.delivered_at) {
        item.delivered_at = now;
        reached.push('delivered');
    }
    if (ack >= 3 && !item.read_at) {
        item.read_at = now;
        reached.push('read');
    }
    if (!reached.length) {
        return;
    }
    persist();
    log(`${item.id} → +${item.to}: ${reached.join(', ')}`);
    callback({ ...item, status: reached.at(-1), at: now });
}

// Looks again, every 2 minutes, at the last 3 days' messages not read yet: ticks the live events missed (the sender
// was off, or the event didn't come), and the WhatsApp id of messages sent without one (findSent).
let rechecking = false;
async function recheck() {
    if (config.dryRun || rechecking || state.status !== 'ready') {
        return;
    }
    rechecking = true;
    try {
        const since = Date.now() - 3 * 86_400_000;
        const open = history.filter((entry) => entry.status === 'sent' && !entry.read_at && Date.parse(entry.done_at) > since).slice(-50);
        for (const item of open.filter((entry) => !entry.wa_id)) {
            // Its text isn't kept here: match by time only.
            item.wa_id = await findSent(`${item.to}@c.us`, null, Date.parse(item.done_at));
            if (item.wa_id) {
                persist();
                log(`${item.id}: found it in the chat, following its ticks.`);
            }
        }
        const acks = await acksOf(open.map((entry) => entry.wa_id).filter(Boolean));
        for (const [waId, ack] of Object.entries(acks)) {
            ticked(waId, ack);
        }
    } catch (error) {
        log(`Could not look at the ticks: ${error.message}`);
    } finally {
        rechecking = false;
    }
}
setInterval(recheck, 2 * 60_000);

async function deliver(message) {
    if (config.dryRun) {
        await sleep(300);

        return `dry_${message.id}`;
    }
    const wid = await client.getNumberId(message.to);
    if (!wid) {
        throw Object.assign(new Error('This number is not on WhatsApp'), { final: true });
    }
    // A moment of "typing…", like a person.
    try {
        const chat = await client.getChatById(wid._serialized);
        await chat.sendStateTyping();
        await sleep(Math.min(3_000, 800 + message.text.length * 15));
    } catch {
        // a new chat has nothing to show it on
    }
    const sentAt = Date.now();
    const sent = await client.sendMessage(wid._serialized, message.text, { linkPreview: false });

    return sent?.id?._serialized ?? (await findSent(wid._serialized, message.text, sentAt));
}

// Ticks straight from WhatsApp Web, since whatsapp-web.js's message_ack has no message id with current WhatsApp Web:
// every change of a sent message's ack calls ticked(). Again after each (re)load of WhatsApp Web.
let ticksExposed = false;
async function hookTicks() {
    try {
        if (!ticksExposed) {
            ticksExposed = true;
            await client.pupPage.exposeFunction('shopTicked', (waId, ack) => ticked(waId, ack));
        }
    } catch {
        // already there (the page keeps it across reloads)
    }
    try {
        await client.pupPage.evaluate(() => {
            if (window.shopTicksHooked) {
                return;
            }
            window.shopTicksHooked = true;
            window.require('WAWebCollections').Msg.on('change:ack', (m) => {
                if (m?.id?.fromMe) {
                    // Newer WhatsApp Web dropped id._serialized; the id's toString() still gives "true_<chat>_<id>".
                    window.shopTicked(m.id._serialized || String(m.id), m.ack);
                }
            });
        });
    } catch (error) {
        log(`Could not follow ticks: ${error.message}`);
    }
}

// The ack of messages we sent, from WhatsApp Web's memory, by id.
async function acksOf(waIds) {
    return client.pupPage.evaluate((ids) => {
        const wanted = new Set(ids);
        const acks = {};
        for (const m of window.require('WAWebCollections').Msg.getModelsArray()) {
            const id = m?.id?.fromMe ? m.id._serialized || String(m.id) : null;
            if (id && wanted.has(id)) {
                acks[id] = m.ack;
            }
        }

        return acks;
    }, waIds);
}

// WhatsApp has been moving chats to new ids ("LID"); whatsapp-web.js then sends the message but doesn't hand it back,
// so its ticks couldn't be followed. Look it up among the messages WhatsApp Web has in memory: the shop's own, with
// this text (when known), to this number (when WhatsApp still shows the number, not the new id), sent closest to that
// time (within 2 minutes), not one already matched to another.
async function findSent(chatId, text, sentAt) {
    try {
        const number = chatId.replace(/@.*/, '');
        const taken = new Set(history.map((item) => item.wa_id).filter(Boolean));
        // Newer WhatsApp Web dropped id._serialized; the id's toString() still gives "true_<chat>_<id>".
        const mine = await client.pupPage.evaluate(
            (from, until) => window.require('WAWebCollections').Msg.getModelsArray()
                .filter((m) => m.id?.fromMe && m.t * 1_000 >= from && m.t * 1_000 <= until)
                .map((m) => ({ id: m.id._serialized || String(m.id), body: m.body ?? null, t: m.t, to: m.to?.user ?? null, server: m.to?.server ?? null }))
                .filter((m) => m.id),
            sentAt - 120_000,
            sentAt + 120_000,
        );
        const candidates = mine
            .filter((m) => !taken.has(m.id) && (text === null || m.body === text) && (m.server !== 'c.us' || m.to === number))
            .map((m) => ({ id: m.id, gap: Math.abs(m.t * 1_000 - sentAt) }))
            .sort((a, b) => a.gap - b.gap);
        if (!candidates.length) {
            log(`Sent message to +${number} not found: ${mine.length} of ours around that time, none matching.`);
        }

        return candidates[0]?.id ?? null;
    } catch (error) {
        log(`Could not look up the sent message: ${String(error?.stack ?? error).split('\n').slice(0, 3).join(' | ')}`);

        return null;
    }
}

async function work() {
    if (working) {
        return;
    }
    working = true;
    try {
        const tooOld = Date.now() - config.maxAgeHours * 3_600_000;
        for (const message of queue.filter((item) => Date.parse(item.queued_at) < tooOld)) {
            finish(message, 'failed', `Not sent within ${config.maxAgeHours} hours (WhatsApp not linked, or the daily limit was reached)`);
        }

        while (queue.length && state.status === 'ready') {
            if (sentToday() >= config.dailyLimit) {
                state.pausedUntil = 'tomorrow (daily limit reached)';
                break;
            }
            state.pausedUntil = null;
            const wait = nextAt - Date.now();
            if (wait > 0) {
                await sleep(wait);
            }
            const message = queue[0];
            if (!message) {
                break;
            }
            try {
                const waId = await deliver(message);
                finish(message, 'sent', null, waId);
                // Dry run: pretend the buyer's phone got it, then they read it.
                if (config.dryRun) {
                    setTimeout(() => ticked(waId, 2), 1_500);
                    setTimeout(() => ticked(waId, 3), 3_000);
                }
            } catch (error) {
                message.attempts = (message.attempts ?? 0) + 1;
                if (error.final || message.attempts >= 3) {
                    finish(message, 'failed', error.message);
                } else {
                    // Try again on the next round, after the others.
                    queue = [...queue.filter((item) => item.id !== message.id), message];
                    persist();
                    log(`${message.id}: attempt ${message.attempts} failed (${error.message}), will try again.`);
                }
            }
            nextAt = Date.now() + (config.gapSeconds + Math.random() * config.jitterSeconds) * 1_000;
        }
    } finally {
        working = false;
    }
}

// Picks up waiting messages after linking, a new day, a failed attempt, and drops expired ones.
setInterval(work, 30_000);

// Tells the website what happened to a message; tries again a few times if the website doesn't answer.
async function callback(item, attempt = 1) {
    if (!config.callbackUrl) {
        return;
    }
    try {
        const response = await fetch(config.callbackUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${config.token}` },
            body: JSON.stringify(item),
            signal: AbortSignal.timeout(15_000),
        });
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
    } catch (error) {
        if (attempt < 4) {
            setTimeout(() => callback(item, attempt + 1), [0, 5_000, 30_000, 120_000][attempt]);
        } else {
            log(`Could not tell the website about ${item.id}: ${error.message}`);
        }
    }
}

// ---------------------------------------------------------------------------------------------------------------
// HTTP API for the website

const tokenHash = crypto.createHash('sha256').update(config.token).digest();
const authorised = (request) => {
    const given = (request.headers.authorization || '').replace(/^Bearer\s+/i, '');

    return crypto.timingSafeEqual(crypto.createHash('sha256').update(given).digest(), tokenHash);
};

const send = (response, status, body) => {
    response.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store' });
    response.end(JSON.stringify(body));
};

const body = (request) => new Promise((resolve, reject) => {
    let data = '';
    request.setEncoding('utf8');
    request.on('data', (chunk) => {
        data += chunk;
        if (data.length > 64_000) {
            reject(new Error('Too large'));
            request.destroy();
        }
    });
    request.on('end', () => {
        try {
            resolve(JSON.parse(data || '{}'));
        } catch {
            reject(new Error('Not JSON'));
        }
    });
    request.on('error', reject);
});

const routes = {
    // Linked or not, the code to scan, and the latest messages: for the dashboard.
    'GET /status': () => [200, {
        status: state.status,
        me: state.me,
        qr: state.qr,
        progress: state.progress,
        error: state.error,
        dry_run: config.dryRun,
        queued: queue.length,
        paused_until: state.pausedUntil,
        sent_today: sentToday(),
        daily_limit: config.dailyLimit,
        recent: history.slice(-10).reverse(),
    }],

    // { id, to: "8562055551234", text, ref }: queued, sent later. The same id again changes nothing.
    'POST /messages': async (request) => {
        const input = await body(request);
        const id = String(input.id ?? '');
        const to = String(input.to ?? '').replace(/\D/g, '');
        const text = String(input.text ?? '').trim();
        if (!/^[\w-]{1,64}$/.test(id) || !/^[1-9]\d{7,14}$/.test(to) || !text || text.length > 4_000) {
            return [422, { error: 'Needs id (letters, digits, - or _), to (8 to 15 digits starting with the country code, like 8562055551234) and text (up to 4000 characters).' }];
        }
        const known = queue.find((item) => item.id === id) || history.find((item) => item.id === id);
        if (known) {
            return [200, { id, status: known.status ?? 'queued' }];
        }
        queue.push({ id, to, text, ref: input.ref ? String(input.ref).slice(0, 100) : null, queued_at: new Date().toISOString(), attempts: 0 });
        persist();
        setImmediate(work);

        return [202, { id, status: 'queued', waiting: queue.length, linked: state.status === 'ready' }];
    },

    // Unlinks the number (a new code appears), for changing the shop's phone.
    'POST /logout': async () => {
        if (config.dryRun || !client || state.status !== 'ready') {
            return [409, { error: 'Not linked.' }];
        }
        state.status = 'starting';
        try {
            await client.logout();
        } catch (error) {
            log(`Unlinking: ${error.message}`);
        }
        restart(1_000);

        return [200, { status: 'unlinked' }];
    },
};

const server = http.createServer(async (request, response) => {
    if (!authorised(request)) {
        return send(response, 401, { error: 'Unauthorised' });
    }
    const route = routes[`${request.method} ${new URL(request.url, 'http://localhost').pathname}`];
    if (!route) {
        return send(response, 404, { error: 'Not found' });
    }
    try {
        const [status, result] = await route(request);
        send(response, status, result);
    } catch (error) {
        send(response, 400, { error: error.message });
    }
});

server.listen(config.port, config.host, () => {
    log(`WhatsApp sender on http://${config.host}:${config.port}${config.callbackUrl ? `, reporting to ${config.callbackUrl}` : ' (no CALLBACK_URL: the website won\'t hear back)'}.`);
    start();
});

// Close WhatsApp Web's browser with the process, so no Chrome is left running.
for (const signal of ['SIGINT', 'SIGTERM']) {
    process.on(signal, async () => {
        log('Stopping.');
        server.close();
        try {
            await client?.destroy();
        } catch {
            // already closed
        }
        process.exit(0);
    });
}
