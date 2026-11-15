<?php

namespace App\Support;

use Carbon\CarbonInterface;
use DateTimeZone;

/**
 * Times for the person looking at the page. Everything is saved in UTC and the site shows Laos time, but members live
 * around the world: public/js/site.js puts their browser's time zone in the "tz" cookie (not encrypted: see
 * bootstrap/app.php), and the Messages page shows chats in it. Event times on pages get a second line in the visitor's
 * own time from site.js itself, so that works on a first visit too.
 */
final class ViewerTime
{
    const LAOS = 'Asia/Vientiane';

    const COOKIE = 'tz';

    /** Their time zone (a name like Europe/Paris), or Laos time when the browser hasn't said or sent something odd. */
    public static function zone(): string
    {
        $zone = request()->cookie(self::COOKIE);

        return is_string($zone) && in_array($zone, DateTimeZone::listIdentifiers(), true) ? $zone : self::LAOS;
    }

    public static function in(CarbonInterface $at): CarbonInterface
    {
        return $at->copy()->setTimezone(self::zone());
    }

    /** The same moment in Laos time (day/month hours:minutes) when their clock shows something else, else null. */
    public static function laosIfDifferent(CarbonInterface $at): ?string
    {
        $laos = $at->copy()->setTimezone(self::LAOS);

        return self::in($at)->getOffset() === $laos->getOffset() ? null : $laos->format('j/n H:i');
    }
}
