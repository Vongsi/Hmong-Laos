<?php

namespace App\Widgets;

use App\Appearance\Background;
use App\Listeners\TrackMemberSubmission;
use Illuminate\Support\Carbon;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\User;
use Statamic\Widgets\Widget;

/**
 * Dashboard list of what members sent ("Sell with us", "Share") that is still a draft, in every language,
 * so moderators don't have to switch the site selector in each collection to find them.
 */
class WaitingForReview extends Widget
{
    const KINDS = ['products' => 'Product', 'posts' => 'News', 'events' => 'Event', 'interviews' => 'Story'];

    public function html()
    {
        $user = User::current();
        $entries = Entry::query()
            ->whereIn('collection', array_keys(TrackMemberSubmission::COLLECTIONS))
            ->where('published', false)
            ->whereNotNull('submitted_by')
            ->get()
            ->filter(fn ($entry) => ! $entry->hasOrigin() && $entry->get('submitted_by') && $user->can('edit', $entry))
            ->sortBy(fn ($entry) => (string) $entry->get('submitted_at'))
            ->map(fn ($entry) => (object) [
                'kind' => self::KINDS[$entry->collectionHandle()] ?? $entry->collectionHandle(),
                'title' => (string) $entry->get('title'),
                'url' => $entry->editUrl(),
                'language' => Site::get($entry->locale())?->name(),
                'by' => (string) $entry->get('submitted_by_name'),
                'sent' => $entry->get('submitted_at') ? Carbon::parse($entry->get('submitted_at'))->setTimezone(Background::TIMEZONE)->format('j M, H:i') : null,
                'note' => (bool) $entry->get('review_note'),
            ]);

        return view('widgets.waiting-for-review', [
            'toReview' => $entries->reject->note->values(),
            'withMember' => $entries->filter->note->values(),
        ]);
    }
}
