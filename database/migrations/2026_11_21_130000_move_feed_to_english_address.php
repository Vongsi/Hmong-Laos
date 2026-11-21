<?php

use Illuminate\Database\Migrations\Migration;
use Statamic\Facades\Entry;

/**
 * The news feed's address was Hmong (/xov-xwm, /lo/xov-xwm…) while the other pages' are English (/events, /inspire),
 * so it is now /feed, and each post's is /feed/{slug} (content/collections/posts.yaml). This renames the Feed page in
 * every language and moves each post's stored address. Old links still work: App\Http\Controllers\OldFeedAddressController.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->move('xov-xwm', 'feed');
    }

    public function down(): void
    {
        $this->move('feed', 'xov-xwm');
    }

    private function move(string $from, string $to): void
    {
        // The Feed page, unless a page already has the new address.
        if (! Entry::query()->where('collection', 'pages')->where('slug', $to)->count()) {
            Entry::query()->where('collection', 'pages')->where('slug', $from)->get()
                ->each(fn ($page) => $page->slug($to)->save());
        }

        // Each post's address is stored with it and not worked out again when the route changes. Changed on the
        // database row rather than by saving the post, so that saving doesn't share it to Facebook or notify anyone.
        $model = config('statamic.eloquent-driver.entries.model');
        $model::query()->where('collection', 'posts')->where('uri', 'like', "/{$from}/%")->get()
            ->each(fn ($post) => $post->update(['uri' => "/{$to}/".substr($post->uri, strlen("/{$from}/"))]));
    }
};
