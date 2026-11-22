<?php

namespace App\Bard;

use Tiptap\Core\Node;

/**
 * A video placed in a member's text on the Share page: the editor (public/js/rich-text.js) writes it as
 * <div data-video-url="…"></div>, this reads it back, and App\Support\RichText turns it into the text's
 * "video" block, as the control panel adds them (and turns those back into this for the editor). Saved
 * text never keeps this node itself.
 */
class VideoBlock extends Node
{
    public static $name = 'videoBlock';

    public function parseHTML()
    {
        return [
            [
                'tag' => 'div[data-video-url]',
                'getAttrs' => fn ($element) => ['url' => trim((string) $element->getAttribute('data-video-url'))],
            ],
        ];
    }

    public function addAttributes()
    {
        return ['url' => []];
    }

    public function renderHTML($node, $HTMLAttributes = [])
    {
        return ['div', ['data-video-url' => (string) ($node->attrs->url ?? '')]];
    }
}
