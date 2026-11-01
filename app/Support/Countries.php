<?php

namespace App\Support;

use Collator;
use Illuminate\Support\Facades\Lang;
use Locale;
use Statamic\Dictionaries\Item;
use Statamic\Facades\Dictionary;
use Statamic\Facades\Site;

/**
 * Countries for members' profiles and the Members page, kept as three-letter codes (LAO, USA…) as in Statamic's
 * countries dictionary, which the control panel uses for the same field. Names are in the site's language: Lao
 * from the PHP intl extension's data, Hmong from lang/hmn/site.php ("countries") where the team has written one,
 * and English otherwise (also wherever intl is missing).
 */
class Countries
{
    const LAOS = 'LAO';

    /** Listed first after Laos: where many Hmong families live. */
    const COMMON = ['THA', 'USA', 'FRA', 'AUS', 'CAN', 'GUF', 'DEU', 'CHN', 'VNM'];

    /** @var array<string, array<string, string>> */
    private static array $options = [];

    /**
     * @return array<string, string> code => name in the site's language, sorted by name
     */
    public static function options(string $site): array
    {
        $lang = Site::get($site)?->lang() ?? 'en';

        return self::$options[$lang] ??= self::build($lang);
    }

    public static function name(?string $code, string $site): ?string
    {
        return $code ? (self::options($site)[$code] ?? null) : null;
    }

    public static function exists(string $code): bool
    {
        return isset(self::options('en')[$code]) || isset(self::options(Site::default()->handle())[$code]);
    }

    /** @return array<string, string> */
    private static function build(string $lang): array
    {
        $names = collect(Dictionary::find('countries')->optionItems())
            ->mapWithKeys(fn (Item $item) => [$item->value() => self::translate($item->value(), $item['iso2'], $item['name'], $lang)])
            ->all();

        $collator = class_exists(Collator::class) ? new Collator($lang === 'hmn' ? 'en' : $lang) : null;
        uasort($names, fn ($a, $b) => $collator ? $collator->compare($a, $b) : strnatcasecmp($a, $b));

        return $names;
    }

    private static function translate(string $code, string $iso2, string $english, string $lang): string
    {
        if ($lang === 'hmn') {
            return Lang::hasForLocale("site.countries.{$code}", 'hmn') ? __("site.countries.{$code}", [], 'hmn') : $english;
        }

        if ($lang !== 'en' && class_exists(Locale::class)) {
            $name = Locale::getDisplayRegion('-'.$iso2, $lang);
            if ($name !== '' && $name !== $iso2) {
                return $name;
            }
        }

        return $english;
    }
}
