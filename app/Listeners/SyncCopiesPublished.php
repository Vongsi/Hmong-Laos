<?php

namespace App\Listeners;

use Statamic\Events\EntrySaved;
use Statamic\Events\EntrySaving;

/**
 * News posts, events and stories get a copy in every language when they are created ("propagate" in
 * content/collections/*.yaml), with the original's published or draft state. Afterwards the copies follow the
 * original: publishing it publishes them, unpublishing it hides them too, translated or not. A copy can still be
 * hidden or shown on its own in the control panel; that holds until the original is published or unpublished again.
 */
class SyncCopiesPublished
{
    const COLLECTIONS = ['posts', 'events', 'interviews'];

    /** @var array<string, bool> originals whose published state this save changes => the new state */
    private static array $changed = [];

    public function saving(EntrySaving $event): void
    {
        $entry = $event->entry;
        if (! in_array($entry->collectionHandle(), self::COLLECTIONS, true) || $entry->hasOrigin()) {
            return;
        }

        // Unknown for an entry that was never saved: its copies are made after it, with its state.
        $was = $entry->getOriginal('published');
        if ($was !== null && (bool) $was !== $entry->published()) {
            self::$changed[$entry->id()] = $entry->published();
        }
    }

    public function saved(EntrySaved $event): void
    {
        $entry = $event->entry;
        if (! array_key_exists($entry->id(), self::$changed)) {
            return;
        }
        $published = self::$changed[$entry->id()];
        unset(self::$changed[$entry->id()]);

        $entry->descendants()
            ->filter(fn ($copy) => $copy->published() !== $published)
            ->each(fn ($copy) => $copy->published($published)->save());
    }
}
