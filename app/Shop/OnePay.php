<?php

namespace App\Shop;

use App\Appearance\Background;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;

/**
 * BCEL OnePay: the customer pays an order by scanning a QR code with the BCEL One app.
 *
 * - The QR code is built here, in BCEL's EMVCo format ("OnePay QR Code Message Format"): our merchant,
 *   the amount, the order number and a reference that only this order has. Each code is valid for a few
 *   minutes; the order page shows a new one (same reference) after that.
 * - BCEL announces each payment on a PubNub channel. `php artisan onepay:listen` receives it and calls
 *   paid(), which finds the order by its reference, checks the amount and marks it paid.
 *
 * Settings: config/onepay.php (.env).
 */
class OnePay
{
    /** How its payments show in Payments (options in the payment blueprint). */
    const BANK = 'bcel';

    const PAYMENT_TYPE = 'qr';

    public static function configured(): bool
    {
        return filled(config('onepay.merchant_id')) && filled(config('onepay.shop_code')) && filled(config('onepay.subscribe_key'));
    }

    public static function testing(): bool
    {
        return (bool) config('onepay.test');
    }

    /** The PubNub channel where BCEL announces every payment to our shop. */
    public static function channel(): string
    {
        return 'mcid-'.config('onepay.merchant_id').'-'.config('onepay.shop_code');
    }

    /**
     * What the customer pays with BCEL One: the order total, or with the test merchant just
     * ONEPAY_TEST_AMOUNT (1 kip), so real test payments cost almost nothing.
     */
    public static function amountDue(EntryContract $order): int
    {
        return self::testing() ? max(1, (int) config('onepay.test_amount')) : (int) $order->get('total');
    }

    /** A new reference for an order (field 62.05; BCEL sends it back as "uuid" when it is paid). */
    public static function newReference(): string
    {
        return Str::lower(Str::random(16));
    }

    /**
     * The QR code for an order: the same one while it is valid, a new one once it has (nearly) expired.
     *
     * @return array{code: string, svg: string, expires: Carbon}
     */
    public static function qr(EntryContract $order): array
    {
        $expires = $order->get('onepay_expires_at') ? Carbon::parse($order->get('onepay_expires_at'), 'UTC') : null;
        if (! $order->get('onepay_ref') || ! $expires || $expires->lt(now()->addMinute())) {
            $expires = now()->addMinutes(max(2, (int) config('onepay.expires_minutes')))->startOfMinute();
            // saveQuietly: not an edit by anyone (no notifications, no "updated by").
            $order->set('onepay_ref', $order->get('onepay_ref') ?: self::newReference())
                ->set('onepay_expires_at', $expires->copy()->utc()->format('Y-m-d H:i:s'))
                ->saveQuietly();
        }

        $code = self::code([
            'amount' => self::amountDue($order),
            'bill' => (string) $order->get('title'),
            'reference' => (string) $order->get('onepay_ref'),
            // BCEL One shows this; letters and numbers only (no Lao or Hmong letters), up to 25.
            'purpose' => 'Order '.$order->get('title'),
            // In Laos time, as the app reads it.
            'expires' => $expires->copy()->setTimezone(Background::TIMEZONE)->format('YmdHis'),
        ]);

        return ['code' => $code, 'svg' => self::svg($code), 'expires' => $expires->copy()->setTimezone(Background::TIMEZONE)];
    }

    /**
     * The QR code's text: EMVCo fields (2-digit id, 2-digit length, value) and a CRC-16/CCITT checksum.
     *
     * @param  array{amount?: ?int, bill?: ?string, reference?: ?string, terminal?: ?string, purpose?: ?string, expires?: ?string}  $payment
     */
    public static function code(array $payment): string
    {
        $raw = self::fields([
            '00' => '01',                // format
            '01' => '11',                // how the code is shown (BCEL's sample uses 11)
            '33' => self::fields([       // merchant
                '00' => 'BCEL',
                '01' => 'ONEPAY',
                '02' => config('onepay.merchant_id'),
                '03' => $payment['expires'] ?? null,
            ]),
            '52' => config('onepay.mcc'),
            '53' => '418',               // Lao kip
            '54' => isset($payment['amount']) ? (string) $payment['amount'] : null,
            '58' => 'LA',
            '60' => config('onepay.city'),
            '62' => self::fields([       // what is paid
                '01' => $payment['bill'] ?? null,
                '05' => $payment['reference'] ?? null,
                '07' => $payment['terminal'] ?? null,
                '08' => $payment['purpose'] ?? null,
            ]),
        ]);

        return $raw.'6304'.self::crc16($raw.'6304');
    }

    /** The QR code as an SVG picture (drawn here, so no other service sees the payment). */
    public static function svg(string $code): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(240, 2), new SvgImageBackEnd)))->writeString($code);

        return trim(preg_replace('/^<\?xml[^>]*>\s*/', '', $svg));
    }

    /**
     * A payment message from BCEL. It looks like (test merchant, 26/10/2026):
     * {"id":1064656147, "service":"ONEPAY", "mcid":"mch…", "iid":"DAH-261026-6M9H", "uuid":"<our reference>",
     *  "txtime":"26/10/2026 15:07:49", "amount":1, "ccy":"LAK", "merchantname":"…", "name":"<payer>",
     *  "phone":"<payer>", "description":"Order DAH-…", "ticket":"BDV9CWNQJNIG", "fccref":"202610265334049"}
     *
     * Finds the order by our reference ("uuid"), checks merchant, order number, currency and amount, and marks
     * it paid at BCEL's payment time. Anything that doesn't match is left for the team to check. Every payment
     * is also kept in Payments (record()), once. Payments that aren't for one of our orders are kept too,
     * except with the test merchant, whose channel carries other people's tests.
     *
     * @return 'paid'|'mismatch'|'twice'|'duplicate'|'unknown'|'ignored'
     */
    public static function paid(array $message): string
    {
        $reference = trim((string) ($message['uuid'] ?? ''));
        if ($reference === '' || (isset($message['mcid']) && $message['mcid'] !== config('onepay.merchant_id'))) {
            return 'ignored';
        }

        // The listener and the every-minute check can receive the same message at the same time.
        return Cache::lock('onepay:payment:'.$reference, 30)->block(15, function () use ($message, $reference) {
            // The same message again: already handled.
            if (self::recorded($message)) {
                return 'duplicate';
            }

            $test = self::testing() ? ' (test)' : '';
            $order = Entry::query()->where('collection', 'orders')->where('onepay_ref', $reference)->first();
            if (! $order) {
                if (! self::testing()) {
                    self::record($message, 'other');
                }

                return 'unknown';
            }

            if ($order->get('payment_status') === 'paid') {
                // A payment was recorded for it before, and this is a different one: the customer paid twice.
                if (self::paymentKey($message) && Payments::bankPaidFor($order)) {
                    self::record($message, 'twice', $order);
                    PlaceOrder::notifyTeam($order, "Order {$order->get('title')} was paid twice with BCEL One{$test}: refund one");

                    return 'twice';
                }
                // Marked paid by hand before BCEL's message arrived: keep the payment, change nothing. BCEL's line
                // replaces the one the team's "Paid" added.
                Payments::dropManual($order);
                self::record($message, 'paid', $order);

                return 'duplicate';
            }

            $due = self::amountDue($order);
            $amount = self::amount($message);
            $problems = array_filter([
                $amount !== null && $amount !== $due ? 'BCEL One payment of '.Shop::kip($amount).', but '.Shop::kip($due).' was due'.($test ? ' (test amount)' : '') : null,
                isset($message['ccy']) && strtoupper((string) $message['ccy']) !== 'LAK' ? 'paid in '.$message['ccy'].', not LAK' : null,
                isset($message['iid']) && (string) $message['iid'] !== (string) $order->get('title') ? 'for order number '.$message['iid'] : null,
                // Paid with a QR code still open in the app after the order was cancelled: refund, or un-cancel it.
                $order->get('status') === 'cancelled' ? 'the order was cancelled before this payment (refund it, or un-cancel the order)' : null,
            ]);

            $order->set('onepay_summary', self::summary($message))
                ->set('onepay_payment', self::summary($message)."\n\n".json_encode($message, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            if ($problems) {
                $order->set('payment_status', 'reported')
                    ->set('payment_note', ucfirst(implode('; ', $problems)).'. Check before setting Paid.')
                    ->save();
                self::record($message, 'check', $order);
                PlaceOrder::notifyTeam($order, "Check the BCEL One payment for order {$order->get('title')}{$test}");

                return 'mismatch';
            }

            // Paid when the customer paid, not when we heard about it. (With the test merchant, the test
            // amount counts as paid in full.)
            // The payment line first, so saving the order as Paid doesn't add a "confirmed by hand" line too.
            self::record($message, 'paid', $order);
            $order->set('payment_status', 'paid')->set('paid_at', self::paidTime($message)->format('Y-m-d H:i'))->save();
            PlaceOrder::notifyTeam($order, "Order {$order->get('title')} paid with BCEL One{$test}");

            return 'paid';
        });
    }

    /** For the team: amount, time, BCEL's references and who paid. */
    public static function summary(array $message): string
    {
        $amount = self::amount($message);

        return implode("\n", array_filter([
            'Paid '.($amount !== null ? Shop::kip($amount) : '?').(isset($message['txtime']) ? ' on '.$message['txtime'] : ''),
            isset($message['ticket']) || isset($message['fccref']) ? 'BCEL references: '.implode(' / ', array_filter([$message['ticket'] ?? null, $message['fccref'] ?? null])) : null,
            isset($message['name']) ? 'From: '.$message['name'].(isset($message['phone']) ? ' ('.$message['phone'].')' : '') : null,
        ]));
    }

    /**
     * Keeps a payment message in Payments (control panel, read only): for reference and for checking against
     * BCEL's statements. The fields are the same for every bank; BCEL's names are mapped to them here.
     *
     * @param  'paid'|'check'|'twice'|'other'  $result
     */
    public static function record(array $message, string $result, ?EntryContract $order = null): void
    {
        $amount = self::amount($message);
        $text = fn (string $key) => isset($message[$key]) && is_scalar($message[$key]) && (string) $message[$key] !== '' ? (string) $message[$key] : null;

        Payments::record([
            'title' => implode(' · ', array_filter([$amount !== null ? Shop::kip($amount) : null, $text('iid'), $text('ticket')])) ?: 'BCEL One payment',
            'result' => $result,
            'bank' => self::BANK,
            'payment_type' => self::PAYMENT_TYPE,
            'amount' => $amount,
            'currency' => $text('ccy'),
            'paid_time' => $text('txtime'),
            'reference_1' => $text('ticket'),
            'reference_2' => $text('fccref'),
            'payer_name' => $text('name'),
            'payer_phone' => $text('phone'),
            'order_number' => $text('iid'),
            'reference' => $text('uuid'),
            'transaction_id' => $text('id'),
            'description' => $text('description'),
            'message' => json_encode($message, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            // Recognises the same message when it comes again.
            'payment_key' => self::paymentKey($message),
            'test' => self::testing() ?: null,
        ], $order, self::paidTime($message));
    }

    /** What identifies one payment: BCEL's transaction id, or else its references ("bcel:id:1064656147"). */
    private static function paymentKey(array $message): ?string
    {
        foreach (['id', 'fccref', 'ticket'] as $key) {
            if (isset($message[$key]) && is_scalar($message[$key]) && (string) $message[$key] !== '') {
                return self::BANK.':'.$key.':'.$message[$key];
            }
        }

        return null;
    }

    private static function recorded(array $message): bool
    {
        $key = self::paymentKey($message);

        return $key !== null && Payments::exists($key);
    }

    /** BCEL's payment time ("26/10/2026 15:07:49", Laos time), in UTC as dates are saved; now if missing. */
    private static function paidTime(array $message): Carbon
    {
        $time = rescue(fn () => Carbon::createFromFormat('d/m/Y H:i:s', (string) ($message['txtime'] ?? ''), Background::TIMEZONE), null, false);

        return ($time ?: now())->utc();
    }

    /** The amount in a payment message, if it has one. */
    private static function amount(array $message): ?int
    {
        foreach (['amount', 'txamount', 'amt'] as $key) {
            if (isset($message[$key]) && is_numeric($message[$key])) {
                return (int) round((float) $message[$key]);
            }
        }

        return null;
    }

    /** @param  array<string, ?string>  $fields */
    private static function fields(array $fields): string
    {
        $out = '';
        foreach ($fields as $id => $value) {
            // Printable ASCII only, and at most 99 characters per field.
            $value = substr(preg_replace('/[^\x20-\x7E]/', '', (string) $value), 0, 99);
            if ($value === '') {
                continue;
            }
            $out .= str_pad((string) $id, 2, '0', STR_PAD_LEFT).str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT).$value;
        }

        return $out;
    }

    private static function crc16(string $data): string
    {
        $crc = 0xFFFF;
        for ($i = 0, $length = strlen($data); $i < $length; $i++) {
            $crc ^= ord($data[$i]) << 8;
            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) & 0xFFFF : ($crc << 1) & 0xFFFF;
            }
        }

        return str_pad(strtoupper(dechex($crc)), 4, '0', STR_PAD_LEFT);
    }
}
