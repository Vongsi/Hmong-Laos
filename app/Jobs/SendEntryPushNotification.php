<?php

namespace App\Jobs;

use App\Models\PushBroadcast;
use App\Models\User;
use App\Notifications\NewEntryPushed;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;
use Throwable;

class SendEntryPushNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    // Collections whose entries can be sent as phone notifications.
    const COLLECTIONS = ['posts', 'events', 'interviews'];

    public function __construct(public string $entryId) {}

    // True when the editor asked for a notification, it hasn't gone out yet, and the entry is live.
    public static function shouldSend(EntryContract $entry): bool
    {
        return in_array($entry->collectionHandle(), self::COLLECTIONS)
            && $entry->get('push_notify')
            && ! PushBroadcast::where('entry_id', $entry->id())->exists()
            && $entry->status() === 'published';
    }

    public function handle(): void
    {
        if (! config('webpush.vapid.public_key') || ! config('webpush.vapid.private_key')) {
            Log::warning('Web push VAPID keys are not set; skipped notifying entry '.$this->entryId);

            return;
        }

        // Two saves close together must not notify twice.
        $lock = Cache::lock('push-broadcast:'.$this->entryId, 300);
        if (! $lock->get()) {
            return;
        }

        try {
            $entry = Entry::find($this->entryId);
            if (! $entry || ! self::shouldSend($entry)) {
                return;
            }

            // Recorded before sending: a phone getting the same notice twice is worse than a missed retry.
            $broadcast = PushBroadcast::create(['entry_id' => $entry->id()]);

            $notification = new NewEntryPushed(
                $entry->id(),
                (string) $entry->get('title'),
                $this->body($entry),
                $entry->absoluteUrl(),
            );

            $sent = 0;
            $this->recipients($entry->locale())->chunkById(200, function ($users) use ($notification, &$sent) {
                Notification::sendNow($users, $notification);
                $sent += $users->count();
            });

            $broadcast->update(['recipients' => $sent]);
        } catch (Throwable $e) {
            Log::error('Sending phone notification for entry '.$this->entryId.' failed: '.$e->getMessage());
        } finally {
            $lock->release();
        }
    }

    // Members with notifications turned on whose site language matches the entry's. No choice counts as Hmong.
    private function recipients(string $site)
    {
        return User::query()
            ->whereHas('pushSubscriptions')
            ->where(function ($q) use ($site) {
                $q->where('preferred_lang', $site);
                if ($site === 'hm') {
                    $q->orWhereNull('preferred_lang')->orWhere('preferred_lang', '');
                }
            });
    }

    private function body(EntryContract $entry): string
    {
        $summary = $entry->get('excerpt') ?: $entry->get('summary') ?: $entry->get('intro');

        return Str::limit(trim(strip_tags((string) $summary)), 140);
    }
}
