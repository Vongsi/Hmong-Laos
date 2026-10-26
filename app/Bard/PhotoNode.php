<?php

namespace App\Bard;

use Statamic\Fieldtypes\Bard\ImageNode;

/** Statamic's Bard image, with a size and position (see PhotoLayout). */
class PhotoNode extends ImageNode
{
    use PhotoLayout;
}
