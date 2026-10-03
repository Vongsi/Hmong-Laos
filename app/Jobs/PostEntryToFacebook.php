<?php

namespace App\Jobs;

use App\Models\FacebookPost;
use App\Services\FacebookPage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;
use Throwable;

class PostEntryToFacebook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    // Collections whose entries can be posted to the Page.
    const COLLECTIONS = ['posts', 'events', 'interviews'];

    public function __construct(public string $entryId) {}

    // True when the editor asked for a Facebook post, it hasn't been made yet, and the entry is live.
    public static function shouldPost(EntryContract $entry): bool
    {
        return in_array($entry->collectionHandle(), self::COLLECTIONS)
            && $entry->get('facebook_share')
            && ! FacebookPost::where('entry_id', $entry->id())->exists()
            && $entry->status() === 'published';
    }

    public function handle(FacebookPage $page): void
    {
        if (! $page->configured()) {
            Log::warning('Facebook Page is not configured; skipped posting entry '.$this->entryId);

            return;
        }

        // Two saves close together must not post twice.
        $lock = Cache::lock('facebook-post:'.$this->entryId, 120);
        if (! $lock->get()) {
            return;
        }

        try {
            $entry = Entry::find($this->entryId);
            if (! $entry || ! self::shouldPost($entry)) {
                return;
            }

            $postId = $page->publishLink($entry->absoluteUrl(), $this->message($entry));

            FacebookPost::create(['entry_id' => $entry->id(), 'facebook_post_id' => $postId]);
        } catch (Throwable $e) {
            // Nothing is recorded, so the scheduled retry picks it up again.
            Log::error('Posting entry '.$this->entryId.' to Facebook failed: '.$e->getMessage());
        } finally {
            $lock->release();
        }
    }

    private function message(EntryContract $entry): string
    {
        $summary = $entry->get('excerpt') ?: $entry->get('summary') ?: $entry->get('intro');

        return trim($entry->get('title')."\n\n".trim(strip_tags((string) $summary)));
    }
}
