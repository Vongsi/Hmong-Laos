<?php

namespace App\Appearance;

use Carbon\CarbonInterface;
use Statamic\Contracts\Assets\Asset as AssetContract;
use Statamic\Facades\Asset;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Image;

/** The page background photo the team picks in Globals → Appearance, including planned seasons. */
class Background
{
    // Seasons are planned in Laos dates, whatever timezone the server runs in.
    const TIMEZONE = 'Asia/Vientiane';

    // How much of the page colour is laid over the photo. 82% is the least that keeps the greyest
    // text at 4.5:1 contrast (WCAG AA) over any photo, black or white, in light and dark mode.
    const VEIL = ['subtle' => 92, 'medium' => 87, 'strong' => 82];

    /**
     * The photo to show now: a running season's, otherwise the default one.
     *
     * @return array{large: string, small: string, veil: int, season: ?string}|null
     */
    public static function current(?CarbonInterface $now = null): ?array
    {
        $settings = GlobalSet::findByHandle('appearance')?->in('hm');
        if (! $settings) {
            return null;
        }

        $today = ($now ?? now())->copy()->setTimezone(self::TIMEZONE)->toDateString();
        $veil = self::VEIL[$settings->get('background_strength')] ?? self::VEIL['subtle'];

        foreach ((array) $settings->get('seasonal_backgrounds') as $season) {
            $from = substr((string) ($season['from'] ?? ''), 0, 10);
            $until = substr((string) ($season['until'] ?? ''), 0, 10);

            // Y-m-d strings compare correctly as text.
            if ($from && $until && $from <= $today && $today <= $until && ($asset = self::asset($season['image'] ?? null))) {
                return self::urls($asset, $veil) + ['season' => $season['name'] ?? null];
            }
        }

        $asset = self::asset($settings->get('background_image'));

        return $asset ? self::urls($asset, $veil) + ['season' => null] : null;
    }

    private static function asset(mixed $value): ?AssetContract
    {
        $path = is_array($value) ? ($value[0] ?? null) : $value;

        return $path ? Asset::find('assets::'.$path) : null;
    }

    /** A landscape version for wide screens and a portrait crop for phones, both around the focal point. */
    private static function urls(AssetContract $asset, int $veil): array
    {
        // "v" changes when the photo is replaced, so browsers and the offline cache fetch the new one.
        $version = $asset->lastModified()?->timestamp ?? 0;

        return [
            'large' => Image::manipulate($asset, ['w' => 1920, 'h' => 1200, 'fit' => 'crop_focal', 'fm' => 'jpg', 'q' => 70, 'v' => $version]),
            'small' => Image::manipulate($asset, ['w' => 800, 'h' => 1400, 'fit' => 'crop_focal', 'fm' => 'jpg', 'q' => 65, 'v' => $version]),
            'veil' => $veil,
        ];
    }
}
