<?php

namespace Tests\Feature;

use App\Models\PushBroadcast;
use App\Models\User;
use App\Notifications\NewEntryPushed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Statamic\Facades\Entry;
use Tests\TestCase;

class PushNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'webpush.vapid.public_key' => 'test-public',
            'webpush.vapid.private_key' => 'test-private',
        ]);
    }

    private function member(?string $lang, bool $subscribed = true): User
    {
        $user = User::create(['name' => 'Member', 'email' => uniqid().'@example.com', 'password' => 'secret-pass']);
        $user->forceFill(['preferred_lang' => $lang])->save();
        if ($subscribed) {
            $user->updatePushSubscription('https://push.example.com/'.uniqid(), 'key', 'auth', 'aes128gcm');
        }

        return $user;
    }

    private function makeEvent(array $data = [], bool $published = true, string $site = 'hm')
    {
        return Entry::make()
            ->collection('events')
            ->locale($site)
            ->slug('push-test-'.uniqid())
            ->published($published)
            ->data(array_merge(['title' => 'Noj Peb Caug', 'summary' => 'See you there.', 'start_date' => now()->addWeek()->format('Y-m-d')], $data));
    }

    public function test_ticked_event_notifies_matching_members_once(): void
    {
        Notification::fake();
        $hmong = $this->member('hm');
        $noChoice = $this->member(null);
        $lao = $this->member('lo');
        $notSubscribed = $this->member('hm', false);

        $entry = $this->makeEvent(['push_notify' => true]);
        $entry->save();
        $entry->save();

        Notification::assertSentTo([$hmong, $noChoice], NewEntryPushed::class, fn ($n) => $n->title === 'Noj Peb Caug'
            && $n->body === 'See you there.'
            && $n->url === $entry->absoluteUrl());
        Notification::assertSentTimes(NewEntryPushed::class, 2);
        Notification::assertNotSentTo([$lao, $notSubscribed], NewEntryPushed::class);
        $this->assertSame(2, PushBroadcast::where('entry_id', $entry->id())->value('recipients'));
    }

    public function test_lao_entry_goes_to_lao_members(): void
    {
        Notification::fake();
        $hmong = $this->member('hm');
        $lao = $this->member('lo');

        $this->makeEvent(['push_notify' => true], true, 'lo')->save();

        Notification::assertSentTo($lao, NewEntryPushed::class);
        Notification::assertNotSentTo($hmong, NewEntryPushed::class);
    }

    public function test_unticked_or_draft_entry_sends_nothing(): void
    {
        Notification::fake();
        $this->member('hm');

        $this->makeEvent()->save();
        $draft = $this->makeEvent(['push_notify' => true], false);
        $draft->save();

        Notification::assertNothingSent();
        $this->assertSame(0, PushBroadcast::count());

        // Publishing later sends it.
        $draft->published(true)->save();
        Notification::assertSentTimes(NewEntryPushed::class, 1);
    }

    public function test_message_payload_opens_the_entry(): void
    {
        $message = (new NewEntryPushed('abc', 'Title', 'Body', 'https://example.com/events/x'))
            ->toWebPush(new User, new NewEntryPushed('abc', 'Title', 'Body', 'https://example.com/events/x'))
            ->toArray();

        $this->assertSame('Title', $message['title']);
        $this->assertSame(['url' => 'https://example.com/events/x'], $message['data']);
        $this->assertSame('entry-abc', $message['tag']);
    }

    public function test_member_can_subscribe_and_unsubscribe(): void
    {
        $user = $this->member('hm', false);
        $body = ['endpoint' => 'https://push.example.com/abc', 'keys' => ['p256dh' => 'key', 'auth' => 'auth'], 'contentEncoding' => 'aes128gcm'];

        $this->post('/push/subscriptions', $body)->assertRedirect('/join');
        $this->postJson('/push/subscriptions', $body)->assertUnauthorized();
        $this->assertSame(0, $user->pushSubscriptions()->count());

        $this->actingAs($user)->postJson('/push/subscriptions', $body)->assertSuccessful();
        $this->assertSame(1, $user->pushSubscriptions()->count());

        $this->actingAs($user)->deleteJson('/push/subscriptions', ['endpoint' => 'https://push.example.com/abc'])->assertSuccessful();
        $this->assertSame(0, $user->pushSubscriptions()->count());
    }

    public function test_manifest_is_per_language(): void
    {
        $this->get('/manifest/lo.webmanifest')
            ->assertOk()
            ->assertJsonPath('lang', 'lo')
            ->assertJsonPath('start_url', '/lo/?source=app')
            ->assertJsonPath('display', 'standalone');
        // An unknown language falls back to the main (Hmong) site.
        $this->get('/manifest/xx.webmanifest')->assertOk()->assertJsonPath('start_url', '/?source=app');
    }
}
