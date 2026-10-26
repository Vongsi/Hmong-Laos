<?php

namespace App\Livewire\Concerns;

use App\Mail\TeamNotice;
use App\Shop\Shop;
use App\Support\PlainText;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Asset;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Throwable;

/**
 * What members' own submissions have in common (products in SellerProducts; news, events and stories in
 * MemberPosts): they are saved as drafts marked with `submitted_by`, a moderator publishes them or writes a
 * "Message to the seller" (App\Listeners\TrackMemberSubmission), and members can change or withdraw them
 * until they are published.
 *
 * Templates print field values as they are (Antlers doesn't escape), so everything a member types goes
 * through App\Support\PlainText (or paragraphs() for Bard fields) before it is saved.
 */
trait ManagesSubmissions
{
    /** Paragraphs from a plain-text box, escaped, as HTML for a Bard field. */
    protected static function paragraphs(?string $text): ?string
    {
        $paragraphs = array_filter(array_map('trim', preg_split("/\n{2,}/", PlainText::lines($text)) ?: []));

        return $paragraphs ? implode('', array_map(fn ($p) => '<p>'.nl2br(e($p), false).'</p>', $paragraphs)) : null;
    }

    /** The plain text of a Bard field, to put back into the text box. */
    protected static function bardText(EntryContract $entry, string $field): string
    {
        $html = (string) $entry->augmentedValue($field);
        $text = preg_replace(['#<br\s*/?>#i', '#</p>\s*#i'], ["\n", "\n\n"], $html);

        return trim(html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5));
    }

    /** Keep the team's formatting of a Bard field unless the member changed the text. */
    protected static function bardValue(?EntryContract $entry, string $field, string $text): ?string
    {
        if ($entry && self::bardText($entry, $field) === PlainText::lines($text)) {
            return $entry->get($field);
        }

        return self::paragraphs($text);
    }

    /** True when the member may send another one now (and counts this one). */
    protected function allowSubmission(string $kind, int $perHour): bool
    {
        $key = $kind.':'.Auth::id();
        if (RateLimiter::tooManyAttempts($key, $perHour)) {
            return false;
        }
        RateLimiter::hit($key, 3600);

        return true;
    }

    /**
     * The signed-in member's own submission that is still a draft (published ones are changed by the team).
     *
     * @param  list<string>  $collections
     */
    protected function ownDraft(string $id, array $collections): EntryContract
    {
        $entry = Entry::find($id);
        abort_unless(
            $entry && in_array($entry->collectionHandle(), $collections, true) && ! $entry->hasOrigin() && ! $entry->published()
            && self::submittedBy($entry, Auth::id()),
            403
        );

        return $entry;
    }

    protected static function submittedBy(EntryContract $entry, $userId): bool
    {
        return $userId !== null && in_array((string) $userId, array_map('strval', (array) $entry->get('submitted_by')), true);
    }

    /**
     * The signed-in member's submissions in these collections, newest first.
     *
     * @param  list<string>  $collections
     * @return Collection<int, EntryContract>
     */
    protected function mySubmissions(array $collections): Collection
    {
        if (! Auth::id()) {
            return collect();
        }

        return Entry::query()->whereIn('collection', $collections)
            ->whereJsonContains('submitted_by', (string) Auth::id())->get()
            ->filter(fn ($entry) => ! $entry->hasOrigin() && self::submittedBy($entry, Auth::id()))
            ->sortByDesc(fn ($entry) => (string) $entry->get('submitted_at'))
            ->values();
    }

    /** live, changes (the team asked for changes) or waiting. */
    protected static function reviewStatus(EntryContract $entry): string
    {
        return $entry->published() ? 'live' : ($entry->get('review_note') ? 'changes' : 'waiting');
    }

    /** Where a member's photos for this kind of submission go: assets/<section>/members/<user id>. */
    protected static function photoFolder(string $section, $userId): string
    {
        return $section.'/members/'.$userId;
    }

    /** Saves uploaded photos under a random name and returns their asset paths. */
    protected static function storePhotos(array $files, string $folder): array
    {
        $paths = [];
        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }
            $extension = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'jpg');
            $asset = Asset::make()->container('assets')->path($folder.'/'.Str::lower(Str::random(8)).'.'.$extension);
            if ($asset->upload($file)) {
                $paths[] = $asset->path();
            }
        }

        return $paths;
    }

    /** Deletes the photos the member uploaded themselves (never photos the team added). */
    protected static function deleteOwnPhotos(array $paths, string $folder): void
    {
        foreach ($paths as $path) {
            if (is_string($path) && str_starts_with($path, $folder.'/')) {
                Asset::find('assets::'.$path)?->delete();
            }
        }
    }

    /** Deletes a withdrawn submission with any translations of it. */
    protected static function deleteSubmission(EntryContract $entry): void
    {
        $entry->deleteDescendants();
        $entry->delete();
    }

    /** @param  list<string>  $lines */
    protected static function tellTeam(EntryContract $entry, string $subject, array $lines): void
    {
        $to = Shop::setting(Site::default()->handle(), 'notify_email');
        if (! $to) {
            Log::warning("Members: no 'Send new orders to' address in Shop settings; nobody was told about entry {$entry->id()}.");

            return;
        }

        try {
            Mail::to($to)->send(new TeamNotice(
                $subject.': '.$entry->get('title'),
                [...$lines, 'It is not on the website until someone publishes it.'],
                $entry->editUrl(),
                'Review it in the control panel',
            ));
        } catch (Throwable $e) {
            Log::error("Members: could not email the team about entry {$entry->id()}: {$e->getMessage()}");
        }
    }
}
