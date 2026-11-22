<?php

namespace App\Tags;

use App\Support\VideoLinks;
use Statamic\Fields\Value;
use Statamic\Tags\Tags;

/**
 * {{ video_player :url="video_url" }}…{{ /video_player }}: a video link's parts for the page (App\Support\VideoLinks).
 * For a YouTube video: video_youtube, video_vertical (a Short), video_thumb, video_embed (the player, loaded by a
 * tap) and video_watch (on YouTube). For any other link: video_link, and video_facebook when it's Facebook's. All
 * prefixed, so they can't be mistaken for the entry's own fields. Renders nothing without a link.
 *
 * {{ video_player:first }}…{{ /video_player:first }}: for cards, the entry's first video: its main video, else the first
 * one placed in its text (posts, events) or among its blocks (interviews). The same parts, plus video_any (it has a
 * video at all); always renders, so a card still shows its photo without one.
 */
class VideoPlayer extends Tags
{
    protected static $handle = 'video_player';

    /** The parts when there's no video. */
    const NONE = [
        'video_youtube' => false,
        'video_vertical' => false,
        'video_thumb' => '',
        'video_embed' => '',
        'video_watch' => '',
        'video_link' => '',
        'video_facebook' => false,
    ];

    public function index()
    {
        return self::parts((string) $this->params->get('url')) ?? '';
    }

    public function first()
    {
        $links = [(string) $this->raw('video_url')];
        foreach ((array) $this->raw('content') as $node) {
            if (is_array($node) && ($node['type'] ?? null) === 'set' && ($node['attrs']['values']['type'] ?? null) === 'video') {
                $links[] = (string) ($node['attrs']['values']['video_url'] ?? '');
            }
        }
        foreach ((array) $this->raw('qa') as $row) {
            if (is_array($row) && ($row['type'] ?? null) === 'video') {
                $links[] = (string) ($row['video_url'] ?? '');
            }
        }

        foreach ($links as $link) {
            if ($parts = self::parts($link)) {
                return $parts + ['video_any' => true];
            }
        }

        return self::NONE + ['video_any' => false];
    }

    /** A link's parts, or null when it's empty or not a web address. */
    private static function parts(string $url): ?array
    {
        $url = trim($url);
        $video = VideoLinks::youtube($url);
        // Other links only as web addresses (older entries may have any link), escaped for the page like the rest.
        $other = ! $video && preg_match('#^https?://#i', $url) ? $url : '';
        if (! $video && $other === '') {
            return null;
        }

        return [
            'video_youtube' => (bool) $video,
            'video_vertical' => (bool) ($video['vertical'] ?? false),
            'video_thumb' => $video ? e(VideoLinks::thumbnail($video['id'])) : '',
            'video_embed' => $video ? e(VideoLinks::embedUrl($video['id'], $video['start'])) : '',
            'video_watch' => $video ? e(VideoLinks::watchUrl($video['id'], $video['start'])) : '',
            'video_link' => e($other),
            'video_facebook' => ! $video && VideoLinks::isFacebook($url),
        ];
    }

    /** A field of the entry the tag is in, as saved. */
    private function raw(string $key): mixed
    {
        $value = $this->context->get($key);

        return $value instanceof Value ? $value->raw() : $value;
    }
}
