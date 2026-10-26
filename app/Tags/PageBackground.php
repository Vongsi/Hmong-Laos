<?php

namespace App\Tags;

use App\Appearance\Background;
use Statamic\Tags\Tags;

/** {{ page_background }}…{{ bg:large }} {{ bg:small }} {{ bg:veil }}…{{ /page_background }}; renders nothing when no photo is set. */
class PageBackground extends Tags
{
    protected static $handle = 'page_background';

    public function index()
    {
        $background = Background::current();

        return $background ? ['bg' => $background] : '';
    }
}
