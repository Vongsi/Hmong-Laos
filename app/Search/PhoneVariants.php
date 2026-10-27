<?php

namespace App\Search;

use App\Shop\WhatsApp;

/**
 * Search transformer for phone numbers (config/statamic/search.php): indexes the number as stored plus the forms
 * people type, so "020 5555 1234", "02055551234", "2055551234" and "+856 20 5555 1234" all find 8562055551234.
 */
class PhoneVariants
{
    public function handle($value, string $field, $searchable): string
    {
        $value = trim((string) $value);
        $digits = WhatsApp::number($value) ?? preg_replace('/\D/', '', $value);
        $variants = [$value, $digits];
        if (str_starts_with($digits, '856')) {
            $local = substr($digits, 3);
            array_push($variants, $local, '0'.$local);
        }

        return implode(' ', array_unique(array_filter($variants)));
    }
}
