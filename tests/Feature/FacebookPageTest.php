<?php

namespace Tests\Feature;

use App\Models\FacebookPost;
use App\Services\FacebookPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Statamic\Facades\Entry;
use Tests\TestCase;

class FacebookPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.facebook_page.page_id' => '12345',
            'services.facebook_page.token' => 'page-token',
        ]);
        $this->app->forgetInstance(FacebookPage::class);
    }

    private function fakeGraph(array $body = ['id' => '12345_678'], int $status = 200): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response($body, $status)]);
    }

    private function makePost(array $data = [], bool $published = true)
    {
        return Entry::make()
            ->collection('posts')
            ->locale('hm')
            ->slug('fb-test-'.uniqid())
            ->date(now()->subMinute())
            ->published($published)
            ->data(array_merge(['title' => 'Noj Peb Caug', 'excerpt' => 'See you there.'], $data));
    }

    public function test_ticked_published_post_is_posted_once(): void
    {
        $this->fakeGraph();
        $entry = $this->makePost(['facebook_share' => true]);
        $entry->save();
        $entry->save();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.com/v23.0/12345/feed'
            && $request['link'] === $entry->absoluteUrl()
            && $request['message'] === "Noj Peb Caug\n\nSee you there."
            && $request['access_token'] === 'page-token');
        $this->assertSame('12345_678', FacebookPost::where('entry_id', $entry->id())->value('facebook_post_id'));
    }

    public function test_unticked_or_draft_post_is_not_posted(): void
    {
        $this->fakeGraph();
        $this->makePost()->save();
        $this->makePost(['facebook_share' => true], published: false)->save();

        Http::assertNothingSent();
    }

    public function test_draft_is_posted_by_the_pending_command_once_published(): void
    {
        $this->fakeGraph();
        $entry = $this->makePost(['facebook_share' => true], published: false);
        $entry->save();
        Http::assertNothingSent();

        $entry->published(true)->saveQuietly();
        $this->artisan('facebook:post-pending')->assertSuccessful();
        $this->artisan('facebook:post-pending')->assertSuccessful();

        Http::assertSentCount(1);
    }

    public function test_failed_post_is_not_recorded_so_it_is_retried(): void
    {
        $this->fakeGraph(['error' => ['message' => 'bad token']], 400);

        $entry = $this->makePost(['facebook_share' => true]);
        $entry->save();

        $this->assertFalse(FacebookPost::where('entry_id', $entry->id())->exists());
    }
}
