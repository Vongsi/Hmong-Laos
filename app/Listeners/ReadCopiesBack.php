<?php

namespace App\Listeners;

use Statamic\Eloquent\Entries\Entry as EloquentEntry;
use Statamic\Events\EntrySaved;

/**
 * A copy of an entry in another language (made when news, events, stories, pages and products are created, or with
 * "Copy to other languages") starts in memory with all the original's values: statamic/eloquent-driver's
 * makeLocalization() copies them. Saving keeps only what differs from the original, so read back later the copy
 * follows the original; but until the request ends the copy itself still holds everything, its "Share to Facebook"
 * and "Notify members" switches too. Registered before the listeners that read them, this gives a copy that was
 * just saved the values it is read back with, so the rest of the request treats it like any later one does.
 */
class ReadCopiesBack
{
    public function handle(EntrySaved $event): void
    {
        $entry = $event->entry;
        if ($entry instanceof EloquentEntry && $entry->hasOrigin() && $entry->model()) {
            $entry->data(EloquentEntry::fromModel($entry->model())->data());
        }
    }
}
