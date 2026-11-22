<?php

namespace App\Rules;

use App\Support\VideoLinks;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A video link for a post, event or interview: a YouTube video that may be shown on other sites, or a Facebook video
 * (App\Support\VideoLinks). Used by the control panel (the blueprints' video_url fields) and the Share page.
 */
class VideoLink implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $url = is_array($value) ? ($value['url'] ?? '') : (string) $value;
        if (trim($url) === '') {
            return;
        }
        if ($why = VideoLinks::problem($url)) {
            $fail(__($why));
        }
    }
}
