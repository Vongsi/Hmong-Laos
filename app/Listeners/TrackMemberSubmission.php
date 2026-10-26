<?php

namespace App\Listeners;

use App\Mail\SubmissionUpdate;
use App\Models\User;
use App\Shop\Shop;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Statamic\Events\EntrySaved;
use Statamic\Events\EntrySaving;
use Statamic\Facades\Site;
use Throwable;

/**
 * Follows the review of what members send themselves: products ("Sell with us", App\Livewire\SellerProducts)
 * and news, events and stories ("Share", App\Livewire\MemberPosts).
 * - The first time a moderator publishes one, its translations are published too (products get Lao and
 *   English drafts when they are sent) and the member gets an email that it is on the website.
 * - When a moderator saves a "Message to the seller" on a draft, the member gets it by email, once per message.
 * Markers that aren't in the blueprint are kept, because the control panel merges its values into the entry.
 */
class TrackMemberSubmission
{
    /** Collection => the group of texts in lang/{en,lo,hmn}/site.php its emails use. */
    const COLLECTIONS = ['products' => 'sell', 'posts' => 'contribute', 'events' => 'contribute', 'interviews' => 'contribute'];

    /** @var array<string, 'live'|'changes'> entries to tell the member about once their save has gone through */
    private static array $announce = [];

    public function saving(EntrySaving $event): void
    {
        $entry = $event->entry;
        if (! isset(self::COLLECTIONS[$entry->collectionHandle()]) || $entry->hasOrigin() || ! $entry->get('submitted_by')) {
            return;
        }

        $note = trim((string) $entry->get('review_note'));

        if ($entry->published() && ! $entry->get('seller_notified_live_at')) {
            $entry->set('seller_notified_live_at', now()->format('Y-m-d H:i'));
            self::$announce[$entry->id()] = 'live';
        } elseif (! $entry->published() && $note !== '' && $entry->get('review_note_notified') !== md5($note)) {
            $entry->set('review_note_notified', md5($note));
            self::$announce[$entry->id()] = 'changes';
        }
    }

    public function saved(EntrySaved $event): void
    {
        $entry = $event->entry;
        $what = self::$announce[$entry->id()] ?? null;
        if (! $what) {
            return;
        }
        unset(self::$announce[$entry->id()]);

        if ($what === 'live') {
            $entry->descendants()->reject->published()->each(fn ($localization) => $localization->published(true)->save());
        }

        $member = User::find(((array) $entry->get('submitted_by'))[0] ?? null);
        if (! $member?->email) {
            return;
        }

        // Their language, if the entry exists in it; otherwise the language they wrote it in.
        $site = Site::get((string) $member->preferred_lang) && $entry->in((string) $member->preferred_lang)
            ? (string) $member->preferred_lang
            : $entry->locale();
        $localized = $entry->in($site) ?? $entry;
        $group = self::COLLECTIONS[$entry->collectionHandle()];
        $page = $group === 'sell' ? 'sell' : 'share';

        try {
            Mail::to($member->email)->locale(Site::get($site)->lang())->send(new SubmissionUpdate(
                $group,
                (string) $localized->value('title'),
                $what === 'live',
                $what === 'changes' ? trim((string) $entry->get('review_note')) : null,
                $what === 'live' ? $localized->absoluteUrl() : Shop::url($site, $page),
            ));
        } catch (Throwable $e) {
            Log::error("Members: could not email the member about entry {$entry->id()}: {$e->getMessage()}");
        }
    }
}
