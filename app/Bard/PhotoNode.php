<?php

namespace App\Bard;

use App\Support\Watermark;
use Statamic\Facades\Asset;
use Statamic\Facades\Image;
use Statamic\Fieldtypes\Bard\ImageNode;

/** Statamic's Bard image, with a size and position (see PhotoLayout). */
class PhotoNode extends ImageNode
{
    use PhotoLayout;

    /**
     * Photos in texts on the site: sized for the page by Glide rather than the phone's original, and with the
     * watermark. GIFs and SVGs as they are. (The member's editor gets the plain address: RichText::toHtml.)
     */
    protected function getUrl($id)
    {
        $asset = Asset::find($id);
        if (! $asset || ! in_array(strtolower((string) $asset->extension()), ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return $asset?->url();
        }

        return Image::manipulate($asset, ['w' => 1600, 'h' => 1600, 'fit' => 'max', ...Watermark::params()]);
    }
}
