<?php

namespace App\Tags;

use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;
use Statamic\Tags\Tags;

/**
 * {{ translation }}…{{ /translation }}: on a news post, event or story in a language nobody has translated it into yet
 * (they get a copy in every language, content/collections/*.yaml), what to tell visitors and search engines.
 * - translation_missing: the page shows the original's text.
 * - translation_note: says so in the page's language ("Not translated into English yet, so it is shown in Hmong.").
 * - translation_original: the original's address while it is on the site, for <link rel="canonical">, so search
 *   engines see one page instead of the same text three times.
 * A copy counts as translated once any of its text fields is its own. Prefixed like video_player's, so they can't be
 * mistaken for the entry's own fields.
 */
class Translation extends Tags
{
    const COLLECTIONS = ['posts', 'events', 'interviews'];

    /** The fields a translator changes. */
    const TEXT = ['title', 'excerpt', 'summary', 'intro', 'content', 'qa'];

    const NONE = ['translation_missing' => false, 'translation_note' => '', 'translation_original' => ''];

    public function index()
    {
        $entry = Entry::find((string) ($this->context->value('page.id') ?? $this->context->value('id')));
        if (! $entry instanceof EntryContract || ! $entry->hasOrigin()
            || ! in_array($entry->collectionHandle(), self::COLLECTIONS, true) || self::translated($entry)) {
            return self::NONE;
        }

        // Where its text comes from: the original, or the translation it was copied from.
        $source = $entry->origin();
        while ($source->hasOrigin() && ! self::translated($source)) {
            $source = $source->origin();
        }

        return [
            'translation_missing' => true,
            'translation_note' => e(__('site.translation.note', [
                'language' => __('site.translation.languages.'.$entry->locale()),
                'from' => __('site.translation.languages.'.$source->locale()),
            ])),
            'translation_original' => $source->status() === 'published' ? e((string) $source->absoluteUrl()) : '',
        ];
    }

    /**
     * Whether the entry has text of its own (always, for an original). A copy only keeps the fields that differ from
     * the entry it was copied from (App\Listeners\ReadCopiesBack).
     */
    private static function translated(EntryContract $entry): bool
    {
        return ! $entry->hasOrigin() || collect(self::TEXT)->contains(fn ($field) => $entry->data()->has($field));
    }
}
