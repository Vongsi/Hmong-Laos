<?php

namespace Tests\Feature;

use App\Rules\VideoLink;
use App\Support\VideoLinks;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class VideoLinksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // YouTube's oEmbed answers by video: shown elsewhere (200), not allowed (401), gone (404), or unreachable.
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request['url'], PHP_URL_QUERY), $query);

            return match ($query['v'] ?? '') {
                'noEmbedding' => Http::response('Unauthorized', 401),
                'goneForever' => Http::response('Not Found', 404),
                'unreachable' => throw new ConnectionException('timed out'),
                default => Http::response(['title' => 'Noj peb caug 2026', 'author_name' => 'Daim Av Hlub']),
            };
        });
    }

    public function test_youtube_links_of_every_kind_are_read(): void
    {
        $links = [
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ' => ['id' => 'dQw4w9WgXcQ', 'start' => null, 'vertical' => false],
            'https://youtube.com/watch?feature=share&v=dQw4w9WgXcQ&t=90' => ['id' => 'dQw4w9WgXcQ', 'start' => 90, 'vertical' => false],
            'https://m.youtube.com/watch?v=dQw4w9WgXcQ&t=1m30s' => ['id' => 'dQw4w9WgXcQ', 'start' => 90, 'vertical' => false],
            'https://youtu.be/dQw4w9WgXcQ?si=abc&t=1h2m3s' => ['id' => 'dQw4w9WgXcQ', 'start' => 3723, 'vertical' => false],
            'https://www.youtube.com/shorts/aBcDeFgHiJk' => ['id' => 'aBcDeFgHiJk', 'start' => null, 'vertical' => true],
            'https://www.youtube.com/live/aBcDeFgHiJk?feature=shared' => ['id' => 'aBcDeFgHiJk', 'start' => null, 'vertical' => false],
            'https://www.youtube-nocookie.com/embed/aBcDeFgHiJk?start=42' => ['id' => 'aBcDeFgHiJk', 'start' => 42, 'vertical' => false],
            'http://youtu.be/a-b_c-d_e-f' => ['id' => 'a-b_c-d_e-f', 'start' => null, 'vertical' => false],
        ];
        foreach ($links as $url => $video) {
            $this->assertSame($video, VideoLinks::youtube($url), $url);
        }

        foreach (['https://www.youtube.com/@DaimAvHlub', 'https://www.youtube.com/watch?v=short', 'https://youtu.be/dQw4w9WgXcQ/extra',
            'https://evil.example/watch?v=dQw4w9WgXcQ', 'javascript:alert(1)//youtu.be/dQw4w9WgXcQ', 'ftp://youtu.be/dQw4w9WgXcQ', '', null] as $url) {
            $this->assertNull(VideoLinks::youtube($url), (string) $url);
        }
    }

    public function test_facebook_video_links_are_recognised(): void
    {
        $this->assertTrue(VideoLinks::isFacebook('https://www.facebook.com/watch/?v=1234567890'));
        $this->assertTrue(VideoLinks::isFacebook('https://fb.watch/abcDEF123/'));
        $this->assertFalse(VideoLinks::isFacebook('https://facebook.com.example/video'));
        $this->assertFalse(VideoLinks::isFacebook('https://youtu.be/dQw4w9WgXcQ'));
    }

    public function test_youtube_says_which_videos_can_be_shown(): void
    {
        $this->assertNull(VideoLinks::problem('https://youtu.be/dQw4w9WgXcQ'));
        $this->assertSame('site.video.not_embeddable', VideoLinks::problem('https://youtu.be/noEmbedding'));
        $this->assertSame('site.video.not_found', VideoLinks::problem('https://youtu.be/goneForever'));
        // YouTube out of reach: the link is accepted rather than refused.
        $this->assertNull(VideoLinks::problem('https://youtu.be/unreachable'));
        // Facebook isn't asked; other links aren't videos.
        $this->assertNull(VideoLinks::problem('https://www.facebook.com/watch/?v=1234567890'));
        $this->assertSame('site.video.unsupported', VideoLinks::problem('https://vimeo.com/123456'));

        // An answer is remembered for a while.
        VideoLinks::problem('https://youtu.be/noEmbedding');
        $this->assertCount(1, Http::recorded(fn (Request $request) => str_contains($request['url'], 'noEmbedding')));
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), VideoLinks::OEMBED)
            && $request['url'] === 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' && $request['format'] === 'json');
    }

    public function test_the_rule_explains_why_in_the_visitors_language(): void
    {
        $check = fn (string $url) => Validator::make(['video' => $url], ['video' => [new VideoLink]]);

        $this->assertTrue($check('')->passes());
        $this->assertTrue($check('https://www.youtube.com/watch?v=dQw4w9WgXcQ')->passes());
        $this->assertSame(__('site.video.not_embeddable'), $check('https://youtu.be/noEmbedding')->errors()->first('video'));
        $this->assertSame(__('site.video.unsupported'), $check('https://example.com/my-video.mp4')->errors()->first('video'));
    }

    public function test_the_page_shows_a_picture_that_becomes_the_player(): void
    {
        $html = $this->video('https://youtu.be/dQw4w9WgXcQ?t=90');

        $this->assertStringContainsString('data-video="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1&amp;rel=0&amp;playsinline=1&amp;start=90"', $html);
        $this->assertStringContainsString('href="https://www.youtube.com/watch?v=dQw4w9WgXcQ&amp;t=90s"', $html);
        $this->assertStringContainsString('src="https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg"', $html);
        $this->assertStringNotContainsString('<iframe', $html);
        $this->assertStringContainsString('class="video vertical"', $this->video('https://youtube.com/shorts/aBcDeFgHiJk'));
    }

    public function test_other_links_are_buttons_and_unsafe_ones_are_left_out(): void
    {
        $facebook = $this->video('https://www.facebook.com/watch/?v=1234567890');
        $this->assertStringContainsString('href="https://www.facebook.com/watch/?v=1234567890"', $facebook);
        $this->assertStringContainsString(__('site.video.watch_facebook'), $facebook);
        $this->assertStringNotContainsString('data-video', $facebook);

        $this->assertSame('', trim($this->video('javascript:alert(1)')));
        $this->assertSame('', trim($this->video('')));
    }

    /** The video partial, as a post, event or interview page shows it. */
    private function video(string $url): string
    {
        return view('partials.video', ['video_url' => $url])->render();
    }
}
