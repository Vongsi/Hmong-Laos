<?php

namespace App\Tags;

use App\Support\VideoLinks;
use Statamic\Tags\Tags;

/**
 * {{ video_player :url="video_url" }}…{{ /video_player }}: a video link's parts for the page (App\Support\VideoLinks).
 * For a YouTube video: video_youtube, video_vertical (a Short), video_thumb, video_embed (the player, loaded by a
 * tap) and video_watch (on YouTube). For any other link: video_link, and video_facebook when it's Facebook's. All
 * prefixed, so they can't be mistaken for the entry's own fields. Renders nothing without a link.
 */
class VideoPlayer extends Tags
{
    protected static $handle = 'video_player';

    public function index()
    {
        $url = trim((string) $this->params->get('url'));
        if ($url === '') {
            return '';
        }

        $video = VideoLinks::youtube($url);
        // Other links only as web addresses (older entries may have any link), escaped for the page like the rest.
        $other = ! $video && preg_match('#^https?://#i', $url) ? $url : '';
        if (! $video && $other === '') {
            return '';
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
}
