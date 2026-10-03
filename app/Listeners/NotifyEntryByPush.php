<?php

namespace App\Listeners;

use App\Jobs\SendEntryPushNotification;
use Statamic\Events\EntrySaved;

class NotifyEntryByPush
{
    public function handle(EntrySaved $event): void
    {
        if (SendEntryPushNotification::shouldSend($event->entry)) {
            SendEntryPushNotification::dispatch($event->entry->id());
        }
    }
}
