<?php

namespace App\Tags;

use Statamic\Tags\Tags;

/** {{ call_labels }}: the call screen's words in the page's language, for public/js/calls.js (window.HL_CALLS). */
class CallLabels extends Tags
{
    protected static $handle = 'call_labels';

    public function index(): string
    {
        $labels = (array) __('site.calls');

        return '<script>window.HL_CALLS = '.json_encode($labels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE).';</script>';
    }
}
