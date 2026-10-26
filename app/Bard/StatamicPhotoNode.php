<?php

namespace App\Bard;

use Statamic\Fieldtypes\Bard\StatamicImageNode;

/** The control panel's variant of the Bard image (statamic:// links), with a size and position. */
class StatamicPhotoNode extends StatamicImageNode
{
    use PhotoLayout;
}
