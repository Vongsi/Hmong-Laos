<?php

use App\Jobs\PostEntryToFacebook;
use App\Jobs\SendEntryPushNotification;
use App\Shop\OnePay;
use App\Shop\Shop;
use App\Support\Calls;
use App\Support\MarketPrices;
use App\Support\Weather;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Statamic\Facades\Entry;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Posts entries ticked for Facebook that weren't live when saved (scheduled dates) or whose post failed.
Artisan::command('facebook:post-pending', function () {
    Entry::query()
        ->whereIn('collection', PostEntryToFacebook::COLLECTIONS)
        ->where('facebook_share', true)
        ->get()
        ->filter(fn ($entry) => PostEntryToFacebook::shouldPost($entry))
        ->each(fn ($entry) => PostEntryToFacebook::dispatch($entry->id()));
})->purpose('Post entries marked "Also post to our Facebook Page" that are now live');

Schedule::command('facebook:post-pending')->everyTenMinutes()->withoutOverlapping();

// Sends phone notifications for ticked entries that weren't live when saved (scheduled dates).
Artisan::command('push:send-pending', function () {
    Entry::query()
        ->whereIn('collection', SendEntryPushNotification::COLLECTIONS)
        ->where('push_notify', true)
        ->get()
        ->filter(fn ($entry) => SendEntryPushNotification::shouldSend($entry))
        ->each(fn ($entry) => SendEntryPushNotification::dispatch($entry->id()));
})->purpose('Send phone notifications for entries marked "Send a phone notification" that are now live');

Schedule::command('push:send-pending')->everyTenMinutes()->withoutOverlapping();

// World prices of gold, silver and crude oil for the home page (App\Support\MarketPrices). Their sources update daily
// (gold, silver) and weekly (oil).
Artisan::command('market-prices:update', function () {
    ['metals' => $metals, 'oil' => $oil] = MarketPrices::refresh();

    $metals
        ? $this->info("Gold and silver of {$metals['date']}: ".Shop::kip($metals['gold']['baht']).' and '.Shop::kip($metals['silver']['baht']).' per baht ('.MarketPrices::BAHT_GRAMS.' g).')
        : $this->error('Could not get gold and silver; the last prices stay up.');
    $oil
        ? $this->info("Crude oil of {$oil['date']}: Brent {$oil['brent']['barrel_usd']}, WTI {$oil['wti']['barrel_usd']} per barrel.")
        : $this->error('Could not get crude oil; the last prices stay up.');

    return $metals && $oil ? 0 : 1;
})->purpose('Fetch the world prices of gold, silver and crude oil for the home page');

Schedule::command('market-prices:update')->everySixHours()->withoutOverlapping();

// The weather and air quality across Laos for the home page (App\Support\Weather): Open-Meteo updates them every
// quarter of an hour; two requests every half hour stay far within its free use.
Artisan::command('weather:update', function () {
    ['weather' => $weather, 'air' => $air] = Weather::refresh();
    $kept = 'the last readings stay up for up to '.Weather::MAX_AGE_HOURS.' hours.';

    $weather
        ? $this->info('Weather of '.count($weather['places'])." towns at {$weather['time']} Lao time (Vientiane {$weather['places']['vientiane-capital']['temp']}°C).")
        : $this->error("Could not get the weather; {$kept}");
    $air
        ? $this->info('Air quality of '.count($air['places'])." towns at {$air['time']} Lao time (Vientiane AQI ".($air['places']['vientiane-capital']['aqi'] ?? '–').').')
        : $this->error("Could not get the air quality; {$kept}");

    return $weather && $air ? 0 : 1;
})->purpose('Fetch the weather and air quality across Laos for the home page');

Schedule::command('weather:update')->everyThirtyMinutes()->withoutOverlapping();

// Calls nobody answered, or left open when a browser closed (App\Support\Calls).
Schedule::call(fn () => Calls::expireStale())->everyMinute()->name('calls:expire')->withoutOverlapping();

// BCEL One: picks up payments within a minute even when `onepay:listen` isn't running (or missed one).
Schedule::command('onepay:listen --once')->everyMinute()->withoutOverlapping()->when(fn () => OnePay::configured());
