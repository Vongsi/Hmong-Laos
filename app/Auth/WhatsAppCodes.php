<?php

namespace App\Auth;

use App\Shop\WhatsApp;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Six-digit codes sent on WhatsApp to sign in, or to confirm a member's number (App\Livewire\WhatsAppSignIn). They go
 * through the shop's own WhatsApp sender for now, which is free (App\Shop\WhatsApp::sendCode(): the one place to
 * change to send them with the official WhatsApp API's authentication messages later).
 *
 * - A code works for 10 minutes and 5 tries, for one purpose: signing in, or confirming a number for one member.
 * - It is kept scrambled (an HMAC with the app key) in the cache, and never in the WhatsApp log.
 * - At most 3 codes an hour to a number, one a minute, and 10 an hour from one network address, so nobody can use
 *   the site to flood someone's WhatsApp (and get the shop's number blocked); 30 tries an hour from an address.
 */
class WhatsAppCodes
{
    const TTL = 600;

    const TRIES = 5;

    const PER_NUMBER_HOUR = 3;

    const PER_ADDRESS_HOUR = 10;

    const RESEND_AFTER = 60;

    const CHECKS_PER_ADDRESS_HOUR = 30;

    /** "Continue with WhatsApp": the sender set up, and sign-in not switched off (WHATSAPP_SIGN_IN). */
    public static function enabled(): bool
    {
        return WhatsApp::configured() && config('services.whatsapp_sender.sign_in', true);
    }

    /**
     * Sends a new code. On a problem, why: "wait" (a code went less than a minute ago) or "too_many" (with "wait":
     * seconds until another can go), or "unavailable" (the sender didn't take it).
     *
     * @return array{ok: bool, error?: string, wait?: int}
     */
    public static function send(string $number, string $purpose, string $lang, string $address): array
    {
        $key = self::key($number, $purpose);
        $current = Cache::get($key);
        if ($current && time() - $current['sent_at'] < self::RESEND_AFTER) {
            return ['ok' => false, 'error' => 'wait', 'wait' => self::RESEND_AFTER - (time() - $current['sent_at'])];
        }
        $limits = ['wa-code-number:'.$number => self::PER_NUMBER_HOUR, 'wa-code-address:'.$address => self::PER_ADDRESS_HOUR];
        foreach ($limits as $limit => $max) {
            if (RateLimiter::tooManyAttempts($limit, $max)) {
                return ['ok' => false, 'error' => 'too_many', 'wait' => RateLimiter::availableIn($limit)];
            }
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $text = fn (string $shown) => trans('site.auth.wa_code_message', ['code' => $shown, 'brand' => trans('site.brand', [], $lang)], $lang);
        if (WhatsApp::sendCode($number, $text($code), $text('••••••'), self::TTL, $lang)) {
            return ['ok' => false, 'error' => 'unavailable'];
        }

        foreach (array_keys($limits) as $limit) {
            RateLimiter::hit($limit, 3600);
        }
        Cache::put($key, ['hash' => self::hash($key, $code), 'tries' => 0, 'sent_at' => time(), 'expires_at' => time() + self::TTL], self::TTL);

        return ['ok' => true];
    }

    /**
     * Checks a code: "ok" (it is then used up), "wrong" ($left tries left), "locked" (no tries left), "expired" (or
     * never sent), or "too_many" (too many tries from this address).
     */
    public static function check(string $number, string $purpose, string $code, string $address, ?int &$left = null): string
    {
        $limit = 'wa-code-check:'.$address;
        if (RateLimiter::tooManyAttempts($limit, self::CHECKS_PER_ADDRESS_HOUR)) {
            return 'too_many';
        }
        RateLimiter::hit($limit, 3600);

        $key = self::key($number, $purpose);
        $current = Cache::get($key);
        if (! $current || $current['expires_at'] < time()) {
            return 'expired';
        }
        if ($current['tries'] >= self::TRIES) {
            return 'locked';
        }
        if (hash_equals($current['hash'], self::hash($key, preg_replace('/\D/', '', $code)))) {
            Cache::forget($key);

            return 'ok';
        }

        $current['tries']++;
        Cache::put($key, $current, max(1, $current['expires_at'] - time()));
        $left = self::TRIES - $current['tries'];

        return $left > 0 ? 'wrong' : 'locked';
    }

    private static function key(string $number, string $purpose): string
    {
        return 'wa-code:'.$purpose.':'.$number;
    }

    private static function hash(string $key, string $code): string
    {
        return hash_hmac('sha256', $key.'|'.$code, (string) config('app.key'));
    }
}
