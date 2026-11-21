<?php

namespace App\Tags;

use App\Support\MarketPrices as Prices;
use Carbon\CarbonImmutable;
use Statamic\Tags\Tags;

/**
 * {{ market_prices }}…{{ metals:gold:baht }} {{ oil:brent:barrel_usd }} {{ metals:date }}…{{ /market_prices }}: the world
 * prices of gold, silver and crude oil (App\Support\MarketPrices). Each kind is there only while it's up to date, and
 * the tag renders nothing when neither is.
 */
class MarketPrices extends Tags
{
    protected static $handle = 'market_prices';

    public function index()
    {
        $prices = Prices::current();
        if (! $prices) {
            return '';
        }

        $dated = fn (?array $part) => $part ? [...$part, 'date' => CarbonImmutable::parse($part['date'])->format('j M')] : null;

        return [
            'metals' => $dated($prices['metals'] ?? null),
            'oil' => $dated($prices['oil'] ?? null),
            'baht_grams' => Prices::BAHT_GRAMS,
            'metals_source' => Prices::SOURCE_PAGES['metals'],
            'oil_source' => Prices::SOURCE_PAGES['oil'],
        ];
    }
}
