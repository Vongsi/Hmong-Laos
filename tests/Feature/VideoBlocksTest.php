<?php

namespace Tests\Feature;

use App\Livewire\MemberPosts;
use App\Models\User;
use App\Support\RichText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Statamic\Facades\Antlers;
use Statamic\Facades\Blueprint;
use Tests\TestCase;

class VideoBlocksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // YouTube's oEmbed: this one may not be shown on other sites; the others may.
        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => str_contains($request['url'], 'noEmbedding')
            ? Http::response('Unauthorized', 401)
            : Http::response(['title' => 'A video']));
    }

    /** A video block as the editor writes it. */
    private static function video(string $url): string
    {
        return '<div data-video-url="'.e($url).'"></div>';
    }

    public function test_a_members_text_keeps_videos_between_its_paragraphs(): void
    {
        $nodes = RichText::toBard('<p>Before</p>'.self::video('https://youtu.be/dQw4w9WgXcQ?t=5').'<p>Between</p>'
            .self::video('https://www.facebook.com/watch/?v=1234567890').'<p>After</p>');

        $this->assertSame(['paragraph', 'set', 'paragraph', 'set', 'paragraph'], array_column($nodes, 'type'));
        $this->assertSame(['type' => 'video', 'video_url' => 'https://youtu.be/dQw4w9WgXcQ?t=5'], $nodes[1]['attrs']['values']);
        $this->assertIsString($nodes[1]['attrs']['id']);
        $this->assertSame(['https://youtu.be/dQw4w9WgXcQ?t=5', 'https://www.facebook.com/watch/?v=1234567890'], RichText::videoLinks($nodes));
        // A text that is only a video is still something.
        $this->assertNotNull(RichText::toBard(self::video('https://youtu.be/dQw4w9WgXcQ')));
    }

    public function test_videos_inside_lists_or_from_other_sites_are_dropped(): void
    {
        $nodes = RichText::toBard('<p>Text</p><ul><li>'.self::video('https://youtu.be/dQw4w9WgXcQ').'Item</li></ul>'
            .self::video('https://evil.example/video').self::video('javascript:alert(1)').self::video('https://youtu.be/short'));

        $this->assertSame([], RichText::videoLinks($nodes));
        $this->assertSame(['paragraph', 'bulletList'], array_column($nodes, 'type'));
    }

    public function test_the_editor_gets_the_videos_back_in_place(): void
    {
        $html = RichText::toHtml([
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Before']]],
            ['type' => 'set', 'attrs' => ['id' => 'abc', 'values' => ['type' => 'video', 'video_url' => 'https://youtu.be/dQw4w9WgXcQ']]],
            ['type' => 'set', 'attrs' => ['id' => 'def', 'values' => ['type' => 'something_else']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'After']]],
        ]);

        $this->assertSame('<p>Before</p><div data-video-url="https://youtu.be/dQw4w9WgXcQ"></div><p>After</p>', $html);
    }

    public function test_the_page_shows_each_video_where_it_was_placed(): void
    {
        $content = Blueprint::find('collections.posts.post')->field('content')->fieldtype()->augment(RichText::toBard(
            '<p>Before</p>'.self::video('https://youtu.be/dQw4w9WgXcQ').'<p>Between</p>'.self::video('https://youtube.com/shorts/aBcDeFgHiJk').'<p>After</p>'
        ));

        $html = view('partials.content_blocks', ['content' => $content])->render();

        $positions = array_map(fn ($needle) => strpos($html, $needle), ['Before', 'embed/dQw4w9WgXcQ', 'Between', 'embed/aBcDeFgHiJk', 'After']);
        $this->assertNotContains(false, $positions);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions);
        $this->assertStringContainsString('class="video vertical"', $html);
    }

    public function test_cards_find_the_first_video_at_the_top_in_the_text_or_among_the_blocks(): void
    {
        $card = fn (array $entry) => (string) Antlers::parse('{{ video_player:first }}{{ if video_any }}{{ video_thumb }}{{ else }}none{{ /if }}{{ /video_player:first }}', $entry);
        $set = fn ($url) => ['type' => 'set', 'attrs' => ['id' => 'x', 'values' => ['type' => 'video', 'video_url' => $url]]];

        $this->assertSame('https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg', $card(['video_url' => 'https://youtu.be/dQw4w9WgXcQ', 'content' => [$set('https://youtu.be/aBcDeFgHiJk')]]));
        $this->assertSame('https://i.ytimg.com/vi/aBcDeFgHiJk/hqdefault.jpg', $card(['video_url' => '', 'content' => [['type' => 'paragraph'], $set('https://youtu.be/aBcDeFgHiJk')]]));
        $this->assertSame('https://i.ytimg.com/vi/aBcDeFgHiJk/hqdefault.jpg', $card(['qa' => [['type' => 'question_answer', 'question' => 'Q'], ['type' => 'video', 'video_url' => 'https://youtu.be/aBcDeFgHiJk']]]));
        $this->assertSame('none', $card(['video_url' => '', 'content' => [['type' => 'paragraph']]]));
    }

    public function test_the_share_form_checks_each_video_in_the_text(): void
    {
        $this->actingAs(User::create(['name' => 'Mai', 'email' => 'mai@example.com', 'password' => 'secret-pass']));
        $form = fn (string $body) => Livewire::test(MemberPosts::class)->call('create', 'post')
            ->set('title', 'Noj peb caug')->set('body', $body)->call('save');

        // The messages have colons, which assertHasErrors would read as a rule's parameters: compare them directly.
        $blocked = $form('<p>Look at these.</p>'.self::video('https://youtu.be/dQw4w9WgXcQ').self::video('https://youtu.be/noEmbedding'));
        $this->assertSame(__('site.contribute.video_in_text', ['number' => 2, 'problem' => __('site.video.not_embeddable')]), $blocked->errors()->first('body'));

        $tooMany = $form('<p>Too many.</p>'.str_repeat(self::video('https://youtu.be/dQw4w9WgXcQ'), MemberPosts::MAX_TEXT_VIDEOS + 1));
        $this->assertSame(__('site.contribute.too_many_videos', ['max' => MemberPosts::MAX_TEXT_VIDEOS]), $tooMany->errors()->first('body'));
    }
}
