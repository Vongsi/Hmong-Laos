<?php

namespace Tests\Feature;

use App\Livewire\MemberPosts;
use App\Models\User;
use App\Support\RichText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Statamic\Facades\Blink;
use Statamic\Facades\Entry;
use Tests\TestCase;

/**
 * News posts, events and stories get a copy in every language (content/collections/*.yaml), shown or hidden with the
 * original (App\Listeners\SyncCopiesPublished); untranslated copies say so (App\Tags\Translation).
 */
class LanguageCopiesTest extends TestCase
{
    use RefreshDatabase;

    private function makePost(array $data = [], bool $published = true, string $site = 'hm')
    {
        $post = Entry::make()->collection('posts')->locale($site)->slug('copies-'.uniqid())->published($published)->date(now()->subHour())
            ->data($data + ['title' => 'Noj peb caug', 'excerpt' => 'Tuaj koom', 'content' => RichText::toBard('<p>Peb sib ntsib.</p>')]);
        $post->save();

        return $post;
    }

    /**
     * The entry as saved, read again. Entries read or saved stay in Statamic's memory until the request ends, so this
     * forgets them first, like a new request would.
     */
    private static function fresh($entry)
    {
        Blink::flush();

        return Entry::find($entry->id());
    }

    /** A page, as a visitor's new request would show it. */
    private function page(string $url)
    {
        Blink::flush();

        return $this->get($url)->assertOk();
    }

    public function test_a_new_post_gets_a_copy_in_each_language_that_follows_it_until_translated(): void
    {
        $post = $this->makePost();

        $lao = $post->in('lo');
        $english = $post->in('en');
        $this->assertNotNull($lao);
        $this->assertNotNull($english);
        $this->assertTrue($lao->published() && $english->published());
        $this->assertSame('Noj peb caug', $lao->value('title'));
        $this->assertSame($post->slug(), $english->slug());
        // Straight away a copy holds only its own values (App\Listeners\ReadCopiesBack), so it doesn't share the
        // original's "Share to Facebook" or "Notify members".
        $this->assertSame([], Entry::find($english->id())->data()->except('updated_at')->all());

        // Changes to the original reach the copies, also in the lists and filters, which read the saved rows.
        $post->set('title', 'Noj peb caug 2026')->save();
        $this->assertSame('Noj peb caug 2026', self::fresh($english)->value('title'));
        $this->assertSame(1, Entry::query()->where('collection', 'posts')->where('site', 'en')->where('title', 'Noj peb caug 2026')->count());

        // A translated field stays translated; the others keep following.
        self::fresh($lao)->set('title', 'ບຸນປີໃໝ່')->save();
        self::fresh($post)->set('title', 'Noj peb caug 2027')->set('excerpt', 'Tuaj koom nrog peb')->save();
        $this->assertSame('ບຸນປີໃໝ່', self::fresh($lao)->value('title'));
        $this->assertSame('Tuaj koom nrog peb', self::fresh($lao)->value('excerpt'));
        $this->assertSame('Noj peb caug 2027', self::fresh($english)->value('title'));
        $this->assertSame(3, Entry::query()->where('collection', 'posts')->count());
    }

    public function test_copies_are_shown_and_hidden_with_the_original(): void
    {
        $post = $this->makePost([], false);
        $this->assertFalse($post->in('lo')->published());

        self::fresh($post)->published(true)->save();
        $this->assertTrue(self::fresh($post->in('lo'))->published());
        $this->assertTrue(self::fresh($post->in('en'))->published());

        // A copy hidden on its own stays hidden while the original changes otherwise...
        self::fresh($post->in('en'))->published(false)->save();
        self::fresh($post)->set('excerpt', 'Hloov')->save();
        $this->assertFalse(self::fresh($post->in('en'))->published());
        $this->assertTrue(self::fresh($post->in('lo'))->published());

        // ...and unpublishing the original hides every copy, translated or not.
        self::fresh($post->in('lo'))->set('title', 'ບຸນປີໃໝ່')->save();
        self::fresh($post)->published(false)->save();
        $this->assertFalse(self::fresh($post->in('lo'))->published());
        $this->assertFalse(self::fresh($post->in('en'))->published());
    }

    public function test_an_untranslated_copy_says_so_and_points_search_engines_to_the_original(): void
    {
        $post = $this->makePost();
        $canonical = '<link rel="canonical" href="'.$post->absoluteUrl().'">';

        $this->page('/en/feed/'.$post->slug())
            ->assertSee('Not translated into English yet, so it is shown in Hmong.')
            ->assertSee($canonical, false);
        $this->page('/lo/feed/'.$post->slug())->assertSee('ຍັງບໍ່ທັນໄດ້ແປເປັນພາສາລາວ ຈຶ່ງສະແດງເປັນພາສາມົ້ງ.');

        // Not on the original, nor once a copy has text of its own.
        $this->page('/feed/'.$post->slug())->assertDontSee('translation-note')->assertDontSee('rel="canonical"', false);
        self::fresh($post->in('en'))->set('title', 'Hmong New Year')->save();
        $this->page('/en/feed/'.$post->slug())->assertSee('Hmong New Year')
            ->assertDontSee('translation-note')->assertDontSee('rel="canonical"', false);

        // A copy shown while its original is hidden still says so, without sending search engines to a missing page.
        self::fresh($post)->published(false)->save();
        self::fresh($post->in('lo'))->published(true)->save();
        $this->page('/lo/feed/'.$post->slug())->assertSee('translation-note')->assertDontSee('rel="canonical"', false);
    }

    public function test_events_and_stories_say_so_too(): void
    {
        $event = Entry::make()->collection('events')->locale('lo')->slug('copies-event')
            ->data(['title' => 'ງານບຸນ', 'summary' => 'ມາຮ່ວມ', 'start_date' => now()->addWeek()->format('Y-m-d H:i')]);
        $event->save();
        $this->page('/events/copies-event')->assertSee('ງານບຸນ')
            ->assertSee('Tseem tsis tau txhais ua lus Hmoob, yog li qhia ua lus Nplog.');

        $story = Entry::make()->collection('interviews')->locale('hm')->slug('copies-story')->date(now()->subHour())
            ->data(['title' => 'Kuv zaj dab neeg', 'intro' => 'Pib', 'person_name' => 'Nou', 'qa' => [['type' => 'question_answer', 'question' => 'Koj nyob qhov twg?', 'answer' => 'Nyob Vientiane.']]]);
        $story->save();
        $this->page('/en/inspire/copies-story')->assertSee('Koj nyob qhov twg?')
            ->assertSee('Not translated into English yet, so it is shown in Hmong.');
    }

    public function test_a_members_post_is_listed_once_and_its_copies_go_live_with_it(): void
    {
        $member = User::create(['name' => 'Mai', 'email' => 'mai@example.com', 'password' => 'secret-pass']);
        $this->actingAs($member);

        $share = Livewire::test(MemberPosts::class)->call('create', 'post')
            ->set('title', 'Peb lub zos')->set('body', '<p>Zoo nkauj heev.</p>')->call('save')->assertHasNoErrors();

        $entries = Entry::query()->where('collection', 'posts')->get();
        $this->assertCount(3, $entries);
        $this->assertFalse($entries->contains->published());
        $share->assertViewHas('mine', fn ($mine) => count($mine) === 1);

        // A moderator publishes it.
        $origin = $entries->first(fn ($entry) => ! $entry->hasOrigin());
        $origin->published(true)->save();
        $this->assertSame(3, Entry::query()->where('collection', 'posts')->where('published', true)->count());
    }
}
