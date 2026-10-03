<?php

use App\Jobs\PostEntryToFacebook;
use App\Jobs\SendEntryPushNotification;
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
