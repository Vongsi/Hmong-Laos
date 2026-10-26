<?php

namespace App\Tags;

use App\Appearance\HomePopup as Popup;
use Statamic\Facades\Site;
use Statamic\Tags\Tags;

/**
 * {{ home_popup }}…{{ popup:title }} {{ popup:text }} {{ popup:image }} {{ popup:link }} {{ popup:button }} {{ popup:date }} {{ popup:version }}…{{ /home_popup }}
 * Renders nothing when there is no pop-up. The values sit under "popup" so a missing one can't fall back to the page's own
 * variables (the home page has a title, for example).
 */
class HomePopup extends Tags
{
    protected static $handle = 'home_popup';

    public function index()
    {
        $popup = Popup::current(Site::current()->handle());

        return $popup ? ['popup' => $popup] : '';
    }
}
