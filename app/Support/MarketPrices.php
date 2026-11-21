<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

use function Illuminate\Support\defer;

/**
 * World market prices for the home page (resources/views/partials/market_prices.antlers.html), for members'
 * information: gold and silver, and crude oil. All from open data, without keys or accounts:
 *
 * - Gold (XAU), silver (XAG) and the dollar in kip: Exchange API (https://github.com/fawazahmed0/exchange-api, CC0),
 *   which publishes each day's rates.
 * - Crude oil, Brent and WTI, in dollars a barrel: the U.S. Energy Information Administration's daily spot prices
 *   (public domain), from the open datasets/oil-prices project (https://github.com/datasets/oil-prices), else FRED. The
 *   EIA publishes them weekly, so the latest are a few days old.
 *
 * The server fetches them every few hours (routes/console.php) and keeps the last good ones of each, so visitors'
 * browsers never call these sources, and a failed fetch only leaves the last prices up. They are world prices only:
 * gold shops and fuel stations in Laos charge more, as the page says.
 */
final class MarketPrices
{
    /** A baht of gold or silver, as the community weighs it. */
    const BAHT_GRAMS = 15;

    /** A troy ounce, the unit of the world price of gold and silver. */
    const OUNCE_GRAMS = 31.1034768;

    /** A barrel of oil, in litres. */
    const BARREL_LITRES = 158.987294928;

    const CACHE_KEY = 'market-prices';

    /** Prices older than this many days aren't shown: their source has stopped updating. */
    const MAX_AGE_DAYS = ['metals' => 7, 'oil' => 21];

    /** Exchange API's files, on jsDelivr then on the project's own copy at Cloudflare. */
    const RATES = [
        'https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/%s.json',
        'https://latest.currency-api.pages.dev/v1/currencies/%s.json',
    ];

    /** The EIA's daily crude oil prices: the open dataset's file, then FRED's copy (the last two months of it). */
    const OIL = [
        'brent' => ['https://raw.githubusercontent.com/datasets/oil-prices/main/data/brent-daily.csv', 'https://fred.stlouisfed.org/graph/fredgraph.csv?id=DCOILBRENTEU&cosd=%s'],
        'wti' => ['https://raw.githubusercontent.com/datasets/oil-prices/main/data/wti-daily.csv', 'https://fred.stlouisfed.org/graph/fredgraph.csv?id=DCOILWTICO&cosd=%s'],
    ];

    const SOURCE_PAGES = [
        'metals' => 'https://github.com/fawazahmed0/exchange-api',
        'oil' => 'https://www.eia.gov/dnav/pet/pet_pri_spt_s1_d.htm',
    ];

    /**
     * The prices to show, ['metals' => […], 'oil' => […]] with whichever are up to date, or null when there are none.
     * With none at all (a new site, a cleared cache), they're fetched after this page has been sent, at most once every
     * ten minutes.
     */
    public static function current(): ?array
    {
        $stored = Cache::get(self::CACHE_KEY);
        if (! is_array($stored)) {
            if (Cache::add(self::CACHE_KEY.':trying', true, now()->addMinutes(10))) {
                defer(fn () => self::refresh());
            }

            return null;
        }

        $shown = array_filter(self::MAX_AGE_DAYS, fn ($days, $part) => isset($stored[$part]['date'])
            && CarbonImmutable::parse($stored[$part]['date'])->diffInDays(now(), absolute: true) <= $days, ARRAY_FILTER_USE_BOTH);

        return $shown ? array_intersect_key($stored, $shown) : null;
    }

    /**
     * Fetches the latest prices and keeps them: ['metals' => […] or null, 'oil' => […] or null] with what was fetched.
     * Where a fetch failed, the last prices of that kind stay.
     */
    public static function refresh(): array
    {
        $dollar = self::rate('usd');
        $fetched = ['metals' => self::metals(), 'oil' => self::oil($dollar['lak'] ?? null)];

        Cache::forever(self::CACHE_KEY, array_merge(Cache::get(self::CACHE_KEY, []), array_filter($fetched)));

        return $fetched;
    }

    /** Gold and silver: kip for a baht and a gram, dollars for an ounce. */
    private static function metals(): ?array
    {
        $gold = self::rate('xau');
        $silver = $gold ? self::rate('xag') : null;
        if (! $gold || ! $silver) {
            return null;
        }

        $amounts = function (array $ounce) {
            $gram = $ounce['lak'] / self::OUNCE_GRAMS;

            return [
                'baht' => (int) round($gram * self::BAHT_GRAMS, -2),
                'gram' => (int) round($gram, -2),
                'ounce_usd' => self::dollars($ounce['usd']),
            ];
        };

        return ['date' => min($gold['date'], $silver['date']), 'gold' => $amounts($gold), 'silver' => $amounts($silver)];
    }

    /** Crude oil: dollars for a barrel and, with the dollar's rate, kip for a litre. */
    private static function oil(?float $kipPerDollar): ?array
    {
        $brent = self::oilPrice('brent');
        $wti = $brent ? self::oilPrice('wti') : null;
        if (! $brent || ! $wti) {
            return null;
        }

        $amounts = fn (array $barrel) => [
            'barrel_usd' => self::dollars($barrel['usd']),
            'litre' => $kipPerDollar ? (int) round($barrel['usd'] * $kipPerDollar / self::BARREL_LITRES, -2) : null,
        ];

        return ['date' => min($brent['date'], $wti['date']), 'brent' => $amounts($brent), 'wti' => $amounts($wti)];
    }

    /** One currency's or metal's rates from Exchange API, ['date' => '2026-11-20', 'lak' => …, 'usd' => …], or null. */
    private static function rate(string $code): ?array
    {
        foreach (self::RATES as $url) {
            try {
                $json = Http::timeout(10)->acceptJson()->get(sprintf($url, $code))->throw()->json();
            } catch (Throwable) {
                continue;
            }

            $date = $json['date'] ?? null;
            $lak = $json[$code]['lak'] ?? null;
            $usd = $json[$code]['usd'] ?? null;
            if (self::isDate($date) && is_numeric($lak) && $lak > 0 && is_numeric($usd) && $usd > 0) {
                return ['date' => $date, 'lak' => (float) $lak, 'usd' => (float) $usd];
            }
        }

        return null;
    }

    /** The latest daily price of Brent or WTI, ['date' => '2026-11-17', 'usd' => …], or null. */
    private static function oilPrice(string $kind): ?array
    {
        foreach (self::OIL[$kind] as $url) {
            try {
                $csv = Http::timeout(15)->get(sprintf($url, now()->subDays(60)->toDateString()))->throw()->body();
            } catch (Throwable) {
                continue;
            }

            // The last line with a price: days without one (holidays) are empty or "." in these files.
            foreach (array_slice(array_reverse(preg_split('/\R/', trim($csv))), 0, 10) as $line) {
                [$date, $price] = array_pad(str_getcsv($line, escape: ''), 2, null);
                if (self::isDate($date) && is_numeric($price) && $price > 0) {
                    return ['date' => $date, 'usd' => (float) $price];
                }
            }
        }

        return null;
    }

    private static function isDate(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }

    private static function dollars(float $amount): string
    {
        return '$'.number_format($amount, $amount < 1000 ? 2 : 0);
    }
}
