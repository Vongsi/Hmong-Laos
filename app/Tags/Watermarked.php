<?php

namespace App\Tags;

use App\Support\Watermark;
use Statamic\Tags\Glide;

/** {{ watermarked:url width="…" … }}: Statamic's {{ glide }} tag, with the Daim Av Hlub mark on the photo (App\Support\Watermark). */
class Watermarked extends Glide
{
    protected static $handle = 'watermarked';

    public function setParameters($parameters)
    {
        // The template's own parameters win, so a photo can still say mark="" to go without.
        return parent::setParameters(array_merge(Watermark::params(), (array) $parameters));
    }
}
