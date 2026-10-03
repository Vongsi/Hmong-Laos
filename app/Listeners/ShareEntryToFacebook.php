<?php

namespace App\Listeners;

use App\Jobs\PostEntryToFacebook;
use Statamic\Events\EntrySaved;

class ShareEntryToFacebook
{
    public function handle(EntrySaved $event): void
    {
        if (PostEntryToFacebook::shouldPost($event->entry)) {
            PostEntryToFacebook::dispatch($event->entry->id());
        }
    }
}
