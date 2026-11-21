<?php

namespace App\Tags;

use App\Support\Countries;
use App\Support\Weather as Readings;
use Statamic\Facades\Site;
use Statamic\Tags\Tags;

/**
 * {{ weather }}…{{ places }}{{ name }} {{ temp }} {{ high_low }} {{ rain }} {{ aqi }}{{ /places }}…{{ /weather }}: the weather
 * and air quality across Laos (App\Support\Weather), in the visitor's language: the main towns, then every other
 * province (for the visitor's own, which the page shows under them), and the provinces for the visitor to choose from.
 * Missing values are empty strings, not null, so they can't pick up the page's own variables. It renders nothing
 * while there are no readings up to date.
 */
class Weather extends Tags
{
    protected static $handle = 'weather';

    public function index()
    {
        $readings = Readings::current();
        if (! $readings) {
            return '';
        }

        // Hmong pages name places as English does, like the provinces taxonomy.
        $lang = Site::current()->handle() === 'lo' ? 'lo' : 'en';
        $today = Readings::today();

        $row = function (string $slug, bool $main) use ($readings, $lang, $today) {
            $place = Readings::PLACES[$slug];
            $now = $readings['places'][$slug];
            $day = $now['days'][$today] ?? null;
            $kind = self::kind($now['code']);
            $level = $now['aqi'] === null ? null : self::airLevel($now['aqi']);

            return [
                'slug' => $slug,
                'main' => $main,
                'name' => $main ? $place['town'][$lang] : $place[$lang],
                'icon' => self::icon($kind, $now['day']),
                'label' => __("site.weather.kinds.{$kind}"),
                'temp' => $now['temp'].'°',
                'high_low' => $day ? "{$day['max']}°/{$day['min']}°" : '',
                'rain' => $day && $day['rain'] !== null ? $day['rain'].'%' : '',
                'aqi' => $now['aqi'] === null ? '' : (string) $now['aqi'],
                'aqi_level' => $level ?? '',
                'aqi_label' => $level ? __("site.weather.air.{$level}") : '',
            ];
        };

        $places = [];
        foreach (Readings::MAIN as $slug) {
            $places[] = $row($slug, true);
        }
        foreach (array_diff(array_keys(Readings::PLACES), Readings::MAIN) as $slug) {
            $places[] = $row($slug, false);
        }

        $provinces = collect(Readings::PLACES)
            ->map(fn (array $place, string $slug) => ['slug' => $slug, 'name' => $place[$lang]])
            ->sortBy(fn (array $province) => self::sortKey($province['name']))
            ->values()
            ->all();

        return [
            'places' => $places,
            'provinces' => $provinces,
            'read_at' => substr($readings['time'], 11, 5),
            'member_place' => $this->memberPlace() ?? '',
            'weather_source' => Readings::SOURCE_PAGES['weather'],
            'air_source' => Readings::SOURCE_PAGES['air'],
        ];
    }

    /** The signed-in member's province, when they live in Laos and chose one: the page starts with it. */
    private function memberPlace(): ?string
    {
        $user = auth()->user();
        if (! $user || ! $user->province || ($user->country && $user->country !== Countries::LAOS)) {
            return null;
        }

        return collect(Readings::PLACES)->search(fn (array $place) => $place['en'] === $user->province) ?: null;
    }

    /** The kind of weather of a WMO weather code, as Open-Meteo gives (snow doesn't fall in Laos: rain). */
    public static function kind(int $code): string
    {
        return match (true) {
            $code <= 1 => 'clear',
            $code === 2 => 'partly',
            $code === 3 => 'cloudy',
            $code === 45, $code === 48 => 'fog',
            $code >= 51 && $code <= 57 => 'drizzle',
            $code >= 95 => 'storm',
            in_array($code, [65, 67, 75, 82, 86], true) => 'heavy',
            default => 'rain',
        };
    }

    /** The partials/weather icon of a kind of weather, by day or night. */
    private static function icon(string $kind, bool $day): string
    {
        return match ($kind) {
            'clear' => $day ? 'sun' : 'moon',
            'partly' => $day ? 'sun-cloud' : 'moon-cloud',
            'cloudy' => 'cloud',
            'heavy' => 'rain',
            default => $kind,
        };
    }

    /** The US air quality index's level. */
    public static function airLevel(int $aqi): string
    {
        return match (true) {
            $aqi <= 50 => 'good',
            $aqi <= 100 => 'moderate',
            $aqi <= 150 => 'sensitive',
            $aqi <= 200 => 'unhealthy',
            $aqi <= 300 => 'very',
            default => 'hazardous',
        };
    }

    /**
     * Sorts Lao names in the Lao alphabet's order: by the first consonant, so a vowel written before it (ເ ແ ໂ ໄ ໃ)
     * moves after it, and with ສ just after ຈ (Unicode has it later; U+0E89, unused, sits between ຈ and ຊ).
     */
    private static function sortKey(string $name): string
    {
        return str_replace('ສ', "\u{0E89}", preg_replace('/^([\x{0EC0}-\x{0EC4}])(.)/u', '$2$1', $name));
    }
}
