<?php

namespace App\Support;

/**
 * The Daim Av Hlub mark on the site's photos, so people who see or share one know where it is from. Glide adds it when
 * it sizes a photo, never to the uploaded file, so it always sits in the bottom-right corner of whatever crop is shown,
 * in proportion to it, and can be changed or switched off (PHOTO_WATERMARK=false) at any time.
 *
 * It goes on the photos people look at closely or share: product, post, event and story pages, the full-screen viewer,
 * link previews and photos in texts ({{ watermarked:url }}, App\Bard\PhotoNode). Thumbnails, cards and profile photos
 * stay clean. The artwork is resources/brand/watermark.html, rendered to public/images/watermark.png.
 */
class Watermark
{
    /** In public/, where Statamic's Glide looks for watermarks. */
    const FILE = 'images/watermark.png';

    /** Glide parameters for the mark (a quarter of the photo's width, bottom-right), or none when it's off. */
    public static function params(): array
    {
        if (! config('app.photo_watermark')) {
            return [];
        }

        return ['mark' => self::FILE, 'markw' => '24w', 'markpos' => 'bottom-right', 'markpad' => '3w', 'markalpha' => 85];
    }
}
