<?php

namespace App\Appearance;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Asset;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Image;

/** The announcement pop-up on the home page, set in Globals → Home page pop-up. */
class HomePopup
{
    // Photo fields of the collections the pop-up can show, in the order they are tried.
    private const PHOTO_FIELDS = ['image', 'cover', 'portrait', 'images'];

    /**
     * What to show on this site's home page now, or null.
     *
     * @return array{title: string, text: ?string, image: ?string, link: ?string, button: string, date: ?string, version: string}|null
     */
    public static function current(string $site, ?CarbonInterface $now = null): ?array
    {
        $set = GlobalSet::findByHandle('popup');
        $origin = $set?->in('hm');
        $local = $set?->in($site) ?? $origin;

        if (! $origin || ! $origin->get('enabled')) {
            return null;
        }

        $today = ($now ?? now())->copy()->setTimezone(Background::TIMEZONE)->toDateString();
        $from = substr((string) $origin->get('show_from'), 0, 10);
        $until = substr((string) $origin->get('show_until'), 0, 10);
        if (($from && $today < $from) || ($until && $today > $until)) {
            return null;
        }

        $entry = self::entry($origin->get('entry'), $site);

        // A custom text only counts in the language it was written in; otherwise the chosen page's own
        // (translated) text is better than another language's custom one.
        $title = $local->get('title') ?: $entry?->value('title') ?: $origin->get('title');
        if (! $title) {
            return null;
        }

        $summary = $entry ? ($entry->value('summary') ?: $entry->value('excerpt') ?: $entry->value('intro')) : null;
        $event = $entry ? self::eventStart($entry) : null;

        return [
            'title' => $title,
            'text' => $local->get('message') ?: ($summary ? trim(strip_tags((string) $summary)) : null) ?: $origin->get('message'),
            'image' => self::photo($origin->get('image')) ?? ($entry ? self::entryPhoto($entry) : null),
            'link' => $local->get('button_link') ?: $origin->get('button_link') ?: $entry?->url(),
            'button' => $local->get('button_label') ?: __('site.popup.read_more'),
            'date' => $event['date'] ?? null,
            'start' => $event['start'] ?? null,
            'venue' => $event['venue'] ?? null,
            // Changes when the team changes the pop-up, so visitors who closed the old one see the new one.
            // Only the shared settings count (they hold the chosen page's id), so it is the same in every language.
            'version' => substr(md5(json_encode($origin->data()->all())), 0, 12),
        ];
    }

    /** The chosen entry in the visitor's language, if it is published. */
    private static function entry(mixed $value, string $site): ?EntryContract
    {
        $id = is_array($value) ? ($value[0] ?? null) : $value;
        $entry = $id ? Entry::find($id) : null;
        $entry = $entry ? ($entry->in($site) ?? $entry) : null;

        return $entry && $entry->status() === 'published' ? $entry : null;
    }

    private static function entryPhoto(EntryContract $entry): ?string
    {
        foreach (self::PHOTO_FIELDS as $field) {
            if ($url = self::photo($entry->value($field))) {
                return $url;
            }
        }

        return null;
    }

    private static function photo(mixed $value): ?string
    {
        $path = is_array($value) ? ($value[0] ?? null) : $value;
        $asset = $path ? Asset::find('assets::'.$path) : null;

        return $asset ? Image::manipulate($asset, ['w' => 720, 'h' => 400, 'fit' => 'crop_focal', 'fm' => 'jpg', 'q' => 75]) : null;
    }

    /**
     * An event's start: "14 November 2026, 09:00" in Laos time and the visitor's language where Carbon knows it, the
     * same moment as ISO 8601 (public/js/site.js adds it in the visitor's own time), and its venue.
     *
     * @return array{date: string, start: string, venue: ?string}|null
     */
    private static function eventStart(EntryContract $entry): ?array
    {
        if ($entry->collectionHandle() !== 'events' || ! $entry->value('start_date')) {
            return null;
        }

        // Saved in UTC; shown in Laos time like the rest of the site.
        $start = Carbon::parse($entry->value('start_date'), 'UTC')->setTimezone(config('statamic.system.display_timezone') ?: 'UTC');

        return ['date' => $start->translatedFormat('j F Y, H:i'), 'start' => $start->toIso8601String(), 'venue' => $entry->value('venue') ?: null];
    }
}
