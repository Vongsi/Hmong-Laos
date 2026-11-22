<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Video links on posts, events and interviews (their "video_url" field; resources/views/partials/video.antlers.html).
 *
 * A YouTube video plays on the page, in YouTube's privacy mode (youtube-nocookie.com: no tracking cookies until
 * someone presses play), and only once someone taps play: until then it's the video's picture, so a page with videos
 * costs members little mobile data. A Facebook video is a "Watch on Facebook" button: Facebook's player is heavy and
 * follows visitors.
 *
 * When a link is saved (App\Rules\VideoLink), YouTube says whether the video exists and may be shown on other sites
 * (its oEmbed address, no key needed): some owners turn that off, and private or removed videos can't be shown.
 */
final class VideoLinks
{
    /** YouTube's video ids: 11 letters, digits, "-" and "_". */
    const ID = '[A-Za-z0-9_-]{11}';

    const YOUTUBE_HOSTS = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com'];

    const FACEBOOK_HOSTS = ['facebook.com', 'www.facebook.com', 'm.facebook.com', 'web.facebook.com', 'fb.watch', 'www.fb.watch'];

    const OEMBED = 'https://www.youtube.com/oembed';

    /**
     * A YouTube link's video: ['id' => …, 'start' => seconds or null, 'vertical' => a Short], or null when it isn't one.
     * Takes watch, youtu.be, Shorts, live and embed links, with a start time (t= or start=: "90", "90s", "1m30s").
     */
    public static function youtube(?string $url): ?array
    {
        $parts = parse_url(trim((string) $url));
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';
        parse_str($parts['query'] ?? '', $query);
        if (! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return null;
        }

        $id = match (true) {
            $host === 'youtu.be' => preg_match('#^/('.self::ID.')$#', $path, $m) ? $m[1] : null,
            in_array($host, self::YOUTUBE_HOSTS, true) && $path === '/watch' => is_string($query['v'] ?? null) && preg_match('#^'.self::ID.'$#', $query['v']) ? $query['v'] : null,
            in_array($host, self::YOUTUBE_HOSTS, true) => preg_match('#^/(?:shorts|live|embed|v)/('.self::ID.')/?$#', $path, $m) ? $m[1] : null,
            default => null,
        };
        if (! $id) {
            return null;
        }

        return ['id' => $id, 'start' => self::seconds($query['t'] ?? $query['start'] ?? null), 'vertical' => str_starts_with($path, '/shorts/')];
    }

    public static function isFacebook(?string $url): bool
    {
        $parts = parse_url(trim((string) $url));

        return in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && in_array(strtolower($parts['host'] ?? ''), self::FACEBOOK_HOSTS, true);
    }

    /** The player's address: privacy mode, playing at once (it's loaded by a tap), only that channel's videos after. */
    public static function embedUrl(string $id, ?int $start = null): string
    {
        return 'https://www.youtube-nocookie.com/embed/'.$id.'?'.http_build_query(array_filter([
            'autoplay' => 1,
            'rel' => 0,
            'playsinline' => 1,
            'start' => $start,
        ], fn ($value) => $value !== null));
    }

    public static function watchUrl(string $id, ?int $start = null): string
    {
        return 'https://www.youtube.com/watch?v='.$id.($start ? '&t='.$start.'s' : '');
    }

    /** The video's picture (480×360; the page crops it to the player's shape). */
    public static function thumbnail(string $id): string
    {
        return 'https://i.ytimg.com/vi/'.$id.'/hqdefault.jpg';
    }

    /**
     * Why a video link can't be used (a translation key), or null when it can: a YouTube video that exists and may be
     * shown on other sites, or a Facebook video. If YouTube can't be asked just then, the link is accepted.
     */
    public static function problem(?string $url): ?string
    {
        $video = self::youtube($url);
        if (! $video) {
            return self::isFacebook($url) ? null : 'site.video.unsupported';
        }

        return Cache::remember('youtube-oembed:'.$video['id'], 3600, function () use ($video) {
            try {
                $status = Http::timeout(8)->acceptJson()->get(self::OEMBED, ['url' => self::watchUrl($video['id']), 'format' => 'json'])->status();
            } catch (Throwable) {
                return null;
            }

            return match (true) {
                $status === 401, $status === 403 => 'site.video.not_embeddable',
                $status === 400, $status === 404 => 'site.video.not_found',
                default => null,
            };
        });
    }

    /** A start time in seconds: "90", "90s", "1m30s" or "1h2m3s"; null when there's none. */
    private static function seconds(mixed $value): ?int
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        if (ctype_digit($value)) {
            return (int) $value ?: null;
        }
        if (! preg_match('/^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$/', $value, $m)) {
            return null;
        }

        return ((int) ($m[1] ?? 0) * 3600 + (int) ($m[2] ?? 0) * 60 + (int) ($m[3] ?? 0)) ?: null;
    }
}
