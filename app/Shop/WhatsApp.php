<?php

namespace App\Shop;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;
use Statamic\Facades\Search;
use Statamic\Facades\Site;
use Throwable;

/**
 * Order updates to buyers from the shop's own WhatsApp number (App\Listeners\SendOrderWhatsApp), through the
 * separate sender in whatsapp-sender/ (whatsapp-web.js; `php artisan whatsapp:sender` runs it). The sender answers
 * at once and sends later, one message at a time, then reports back (App\Http\Controllers\WhatsAppCallbackController).
 *
 * Every message, with its text, is kept in the WhatsApp messages log (control panel → Collections → WhatsApp
 * messages, read only; one entry each, its id is the sender's message id): to whom, which order, what about, and how
 * far it got, with the times: sent, delivered (on the buyer's phone), read. Orders link to theirs. Any message can be
 * sent again (App\Actions\ResendWhatsAppMessage, the dashboard). One that can't go (sender off, number not on
 * WhatsApp) is emailed to the team with the message ready to send by hand.
 */
class WhatsApp
{
    const COLLECTION = 'whatsapp_messages';

    /**
     * For the log's titles: waiting (with the sender), sent (one tick: reached WhatsApp), delivered (two grey ticks:
     * on the buyer's phone), read (two blue ticks; only when the buyer has read receipts on), or NOT SENT.
     */
    const RESULTS = ['queued' => 'waiting', 'sent' => 'sent', 'delivered' => 'delivered', 'read' => 'read', 'failed' => 'NOT SENT'];

    /** A message only moves forward through these. */
    const STAGES = ['queued' => 0, 'failed' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3];

    /** At most this many messages resent at once, so a slip can't flood buyers (and get the number blocked). */
    const RESEND_AT_ONCE = 20;

    public static function configured(): bool
    {
        return filled(config('services.whatsapp_sender.url')) && filled(config('services.whatsapp_sender.token'));
    }

    /** Set up, and switched on in Shop settings. */
    public static function active(): bool
    {
        return self::configured() && Shop::setting('hm', 'whatsapp_updates') !== false;
    }

    /** Whether buyers get this update by themselves (Shop settings: all of them until the team chooses). */
    public static function sends(string $event): bool
    {
        $events = Shop::setting('hm', 'whatsapp_events');

        return self::active() && ($events === null || in_array($event, (array) $events, true));
    }

    /** International digits for a number as customers type it. Lao numbers like "020 5555 1234" get 856. */
    public static function number(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        } elseif (str_starts_with($digits, '0')) {
            $digits = '856'.substr($digits, 1);
        } elseif (! str_starts_with($digits, '856') && strlen($digits) <= 10) {
            $digits = '856'.$digits;
        }

        return strlen($digits) >= 8 ? $digits : null;
    }

    /**
     * Sends the buyer these updates, in one message: logged straight away, handed to the sender after the response
     * on the website (the team's save doesn't wait for it), or at once from the command line (BCEL One's listener
     * never "responds").
     *
     * @param  list<string>  $events
     */
    public static function notify(EntryContract $order, array $events): void
    {
        $events = array_values(array_filter(array_intersect(OrderMessages::EVENTS, $events), fn (string $event) => self::sends($event)));
        $to = self::number($order->get('customer_phone'));
        if (! $events || ! $to || ! $order->id()) {
            return;
        }

        $message = self::record([
            'kind' => 'order',
            'to' => $to,
            'customer' => $order->get('customer_name'),
            'for_order' => [$order->id()],
            'order_number' => $order->get('title'),
            'events' => $events,
            'about' => implode(', ', array_map(fn (string $event) => OrderMessages::LABELS[$event] ?? $event, $events)),
            'message' => OrderMessages::text($order, $events),
            'language' => Site::get($order->get('customer_site') ?: 'hm')?->lang(),
        ]);
        $id = $message->id();

        app()->runningInConsole()
            ? self::queue($id)
            : dispatch(fn () => WhatsApp::queue($id))->afterResponse();
    }

    /**
     * A sign-in code (App\Auth\WhatsAppCodes): first in the sender's queue, no use after $ttl seconds. Logged like the
     * other messages, with the code hidden ($logText); never resent. Null, or why the sender didn't take it.
     */
    public static function sendCode(string $to, string $text, string $logText, int $ttl, ?string $lang = null): ?string
    {
        $message = self::record(['kind' => 'code', 'to' => $to, 'about' => 'Sign-in code', 'message' => $logText, 'language' => $lang]);

        try {
            self::http()->post('messages', ['id' => $message->id(), 'to' => $to, 'text' => $text, 'priority' => true, 'ttl' => $ttl])->throw();

            return null;
        } catch (Throwable $e) {
            $error = 'The WhatsApp sender didn\'t take it ('.Str::limit($e->getMessage(), 150).')';
            self::report($message->id(), 'failed', $error);

            return $error;
        }
    }

    /** Hands a logged message to the sender. */
    public static function queue(string $id): void
    {
        $message = self::find($id);
        if (! $message) {
            return;
        }

        try {
            self::http()->post('messages', [
                'id' => $id,
                'to' => $message->get('to'),
                'text' => $message->get('message'),
                'ref' => ((array) $message->get('for_order'))[0] ?? null,
            ])->throw();
        } catch (Throwable $e) {
            self::report($id, 'failed', 'The WhatsApp sender didn\'t take it ('.Str::limit($e->getMessage(), 150).')');
        }
    }

    /**
     * What the sender said happened to a message, and when: sent, delivered, read, or failed and why. Only forward
     * (a report arriving late or twice changes nothing); "failed" only before it reached the buyer's phone. A message
     * first given up and then sent after all (the sender got it although it didn't answer in time) becomes sent.
     */
    public static function report(string $id, string $result, ?string $error = null, ?string $at = null): void
    {
        $message = self::find($id);
        if (! $message || ! isset(self::STAGES[$result])) {
            return;
        }
        $current = $message->get('result') ?? 'queued';
        $before = $message->data()->all();
        try {
            $time = ($at ? Carbon::parse($at) : now())->utc()->format('Y-m-d H:i:s');
        } catch (Throwable) {
            $time = now()->utc()->format('Y-m-d H:i:s');
        }
        $fill = fn (string $field) => $message->get($field) ? null : $message->set($field, $time);

        if ($result === 'failed') {
            if (! in_array($current, ['queued', 'sent'], true)) {
                return;
            }
            $message->set('result', 'failed')->set('error', $error ?: 'Not sent')->set('result_at', $time);
        } else {
            $fill('result_at');
            if ($result !== 'sent') {
                $fill('delivered_at');
            }
            if ($result === 'read') {
                $fill('read_at');
            }
            if ($current === 'failed' || self::STAGES[$result] > self::STAGES[$current]) {
                $message->set('result', $result)->set('error', null);
            }
        }
        if ($message->data()->all() == $before) {
            return;
        }
        self::store($message->set('title', self::title($message)));

        $order = self::order($message);
        if ($result === 'failed' && $order) {
            PlaceOrder::notifyTeam(
                $order,
                "Order {$order->get('title')}: WhatsApp message not sent ({$message->get('error')})",
                Shop::whatsappUrl($order->get('customer_phone'), (string) $message->get('message')),
            );
        }
    }

    /** The order's messages, oldest first. */
    public static function messages(EntryContract $order): Collection
    {
        if (! $order->id()) {
            return collect();
        }

        return Entry::query()->where('collection', self::COLLECTION)->whereJsonContains('for_order', $order->id())
            ->orderBy('date')->get()->sortBy(fn (EntryContract $message) => self::sequence($message))->values();
    }

    /** The updates the buyer got or is getting: each goes once (SendOrderWhatsApp), except "Not accepted". */
    public static function sent(EntryContract $order): array
    {
        return self::messages($order)
            ->reject(fn (EntryContract $message) => $message->get('result') === 'failed')
            ->flatMap(fn (EntryContract $message) => (array) $message->get('events'))
            ->unique()->values()->all();
    }

    /** The latest messages, newest first (dashboard). */
    public static function latest(int $count = 10): Collection
    {
        return Entry::query()->where('collection', self::COLLECTION)->orderBy('date', 'desc')->limit($count + 10)->get()
            ->sortByDesc(fn (EntryContract $message) => self::sequence($message))->take($count)->values();
    }

    /** The order a message was about, if it still exists. */
    public static function order(EntryContract $message): ?EntryContract
    {
        $id = ((array) $message->get('for_order'))[0] ?? null;

        return $id ? Entry::find($id) : null;
    }

    /**
     * Linked or not, the code to scan, what is waiting: for the dashboard (App\Widgets\WhatsAppSender).
     *
     * @return array{status: string, error?: ?string, qr?: ?string, me?: ?array, queued?: int}
     */
    public static function status(): array
    {
        if (! self::configured()) {
            return ['status' => 'not_set_up'];
        }
        try {
            return self::http()->timeout(4)->get('status')->throw()->json();
        } catch (Throwable $e) {
            return ['status' => 'offline', 'error' => Str::limit($e->getMessage(), 200)];
        }
    }

    /** A test message from the dashboard, logged like the others; the error, if the sender didn't take it. */
    public static function test(string $phone, string $text, ?string $by = null): ?string
    {
        $to = self::number($phone);
        if (! $to) {
            return 'That doesn\'t look like a phone number.';
        }
        $message = self::record(['kind' => 'test', 'to' => $to, 'message' => $text, 'sent_by' => $by]);
        self::queue($message->id());

        return self::find($message->id())?->get('result') === 'failed' ? (string) self::find($message->id())->get('error') : null;
    }

    /**
     * Sends a logged message again: the same text, to the same number or $to, as a new message in the log linked both
     * ways to the first ("Resend" on the log, and on the dashboard). Handed to the sender straight away, since the
     * team is waiting for the answer. Null, or why not.
     */
    public static function resend(EntryContract $original, ?string $by = null, ?string $to = null): ?string
    {
        if (! self::active()) {
            return 'WhatsApp updates are not set up, or switched off in Shop settings.';
        }
        // A sign-in code is never sent again (its text holds no code, and it would be out of date anyway).
        if ($original->collectionHandle() !== self::COLLECTION || blank($original->get('message')) || $original->get('kind') === 'code') {
            return 'That isn\'t a WhatsApp message.';
        }
        $number = filled($to) ? self::number($to) : $original->get('to');
        if (! $number) {
            return 'That doesn\'t look like a phone number.';
        }

        $copy = self::record(array_filter([
            'kind' => $original->get('kind'),
            'to' => $number,
            'customer' => $original->get('customer'),
            'for_order' => $original->get('for_order'),
            'order_number' => $original->get('order_number'),
            'events' => $original->get('events'),
            'about' => $original->get('about'),
            'message' => $original->get('message'),
            'language' => $original->get('language'),
            'resend_of' => [$original->id()],
            'sent_by' => $by,
        ]));
        self::store($original->set('resent_as', array_values(array_unique([...(array) $original->get('resent_as'), $copy->id()]))));
        self::queue($copy->id());

        $copy = self::find($copy->id());

        return $copy?->get('result') === 'failed' ? (string) $copy->get('error') : null;
    }

    /**
     * Messages to look at, newest first: NOT SENT in the last 7 days, or sent over a day ago and still not on the
     * buyer's phone (wrong number, phone off, WhatsApp not opened: call them). Not those resent since.
     */
    public static function needsLook(int $count = 10): Collection
    {
        return Entry::query()->where('collection', self::COLLECTION)->whereIn('result', ['failed', 'sent'])
            ->where('date', '>=', now()->subDays(14))->orderBy('date', 'desc')->get()
            ->reject(fn (EntryContract $message) => filled($message->get('resent_as')) || $message->get('kind') === 'code')
            ->filter(fn (EntryContract $message) => $message->get('result') === 'failed'
                ? $message->date()->gte(now()->subDays(7))
                : $message->date()->lte(now()->subDay()))
            ->take($count)->values();
    }

    /** Unlinks the shop's number (a new code appears to link another). */
    public static function unlink(): ?string
    {
        try {
            self::http()->post('logout')->throw();

            return null;
        } catch (Throwable $e) {
            return Str::limit($e->getMessage(), 150);
        }
    }

    /** "DAH-261027-ABCD · Order received, Payment received · read", "Test message (again) · NOT SENT". */
    public static function title(EntryContract $message): string
    {
        $what = $message->get('kind') === 'test'
            ? 'Test message'
            : implode(' · ', array_filter([$message->get('order_number'), $message->get('about')]));

        return $what.($message->get('resend_of') ? ' (again)' : '').' · '.(self::RESULTS[$message->get('result')] ?? $message->get('result'));
    }

    /** Adds a message to the log, waiting to be sent. Quietly: no listeners, no notifications. */
    private static function record(array $fields): EntryContract
    {
        $message = Entry::make()->collection(self::COLLECTION)->locale('hm')->published(true)->date(now()->utc())
            ->data(array_filter($fields + ['result' => 'queued', 'queued_at' => now()->utc()->format('Y-m-d H:i:s.u')], fn ($value) => $value !== null && $value !== '' && $value !== []));
        self::store($message->set('title', self::title($message)));

        return $message;
    }

    /**
     * Saves a message quietly (no listeners, no notifications), and keeps the log's search (phone, customer, order
     * number) up to date, which a quiet save skips. A search problem must never lose the message.
     */
    private static function store(EntryContract $message): void
    {
        $message->saveQuietly();
        try {
            Search::updateWithinIndexes($message);
        } catch (Throwable $e) {
            Log::warning("WhatsApp: could not update the search for message {$message->id()}: {$e->getMessage()}");
        }
    }

    /** Sort key: the entry's date keeps whole seconds only, and several messages can go within one. */
    private static function sequence(EntryContract $message): string
    {
        return (string) ($message->get('queued_at') ?? $message->date()?->copy()->utc()->format('Y-m-d H:i:s.u'));
    }

    /** From the database, not a copy this process loaded earlier: the sender's reports change messages any time. */
    private static function find(string $id): ?EntryContract
    {
        $message = Entry::query()->where('id', $id)->first();

        return $message?->collectionHandle() === self::COLLECTION ? $message : null;
    }

    private static function http(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.whatsapp_sender.url'), '/'))
            ->withToken((string) config('services.whatsapp_sender.token'))
            ->acceptJson()
            ->connectTimeout(3)
            ->timeout(8);
    }
}
