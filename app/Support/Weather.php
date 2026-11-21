<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

use function Illuminate\Support\defer;

/**
 * The weather and air quality across Laos for the home page (resources/views/partials/weather.antlers.html): now, today's
 * high and low and chance of rain, and the air quality index, for each province's main town. From Open-Meteo
 * (https://open-meteo.com, CC BY 4.0; free for non-commercial sites, without a key), whose forecasts come from the
 * world's weather models, and whose air quality is the Copernicus Atmosphere Monitoring Service's (CAMS).
 *
 * The server fetches all of them in two requests every half hour (routes/console.php) and keeps the last good ones, so
 * visitors' browsers never call Open-Meteo, and a failed fetch only leaves the last readings up for a while.
 */
final class Weather
{
    const CACHE_KEY = 'weather';

    const TIMEZONE = 'Asia/Vientiane';

    /** Readings older than this many hours aren't shown: "now" would be wrong. */
    const MAX_AGE_HOURS = 3;

    const FORECAST = 'https://api.open-meteo.com/v1/forecast';

    const AIR = 'https://air-quality-api.open-meteo.com/v1/air-quality';

    const SOURCE_PAGES = ['weather' => 'https://open-meteo.com/', 'air' => 'https://atmosphere.copernicus.eu/'];

    /**
     * Each province of Laos, keyed as the provinces taxonomy (and members' profiles, by its English name): its name, and
     * its main town's name (where the reading is) and place. The home page shows the MAIN towns, and the visitor's
     * province under them.
     */
    const PLACES = [
        'vientiane-capital' => ['en' => 'Vientiane Capital', 'lo' => 'ນະຄອນຫຼວງວຽງຈັນ', 'town' => ['en' => 'Vientiane', 'lo' => 'ວຽງຈັນ'], 'at' => [17.9757, 102.6331]],
        'phongsaly' => ['en' => 'Phongsaly', 'lo' => 'ຜົ້ງສາລີ', 'town' => ['en' => 'Phongsaly', 'lo' => 'ຜົ້ງສາລີ'], 'at' => [21.6819, 102.1089]],
        'luang-namtha' => ['en' => 'Luang Namtha', 'lo' => 'ຫຼວງນ້ຳທາ', 'town' => ['en' => 'Luang Namtha', 'lo' => 'ຫຼວງນ້ຳທາ'], 'at' => [20.9486, 101.4019]],
        'oudomxay' => ['en' => 'Oudomxay', 'lo' => 'ອຸດົມໄຊ', 'town' => ['en' => 'Muang Xay', 'lo' => 'ເມືອງໄຊ'], 'at' => [20.6918, 101.9839]],
        'bokeo' => ['en' => 'Bokeo', 'lo' => 'ບໍ່ແກ້ວ', 'town' => ['en' => 'Houayxay', 'lo' => 'ຫ້ວຍຊາຍ'], 'at' => [20.2774, 100.4122]],
        'luang-prabang' => ['en' => 'Luang Prabang', 'lo' => 'ຫຼວງພະບາງ', 'town' => ['en' => 'Luang Prabang', 'lo' => 'ຫຼວງພະບາງ'], 'at' => [19.8856, 102.1347]],
        'houaphanh' => ['en' => 'Houaphanh', 'lo' => 'ຫົວພັນ', 'town' => ['en' => 'Xam Neua', 'lo' => 'ຊຳເໜືອ'], 'at' => [20.4159, 104.0429]],
        'sayaboury' => ['en' => 'Sayaboury', 'lo' => 'ໄຊຍະບູລີ', 'town' => ['en' => 'Sayaboury', 'lo' => 'ໄຊຍະບູລີ'], 'at' => [19.2551, 101.7090]],
        'xieng-khouang' => ['en' => 'Xieng Khouang', 'lo' => 'ຊຽງຂວາງ', 'town' => ['en' => 'Phonsavan', 'lo' => 'ໂພນສະຫວັນ'], 'at' => [19.4600, 103.1800]],
        'vientiane-province' => ['en' => 'Vientiane Province', 'lo' => 'ແຂວງວຽງຈັນ', 'town' => ['en' => 'Phonhong', 'lo' => 'ໂພນໂຮງ'], 'at' => [18.4949, 102.4092]],
        'xaisomboun' => ['en' => 'Xaisomboun', 'lo' => 'ໄຊສົມບູນ', 'town' => ['en' => 'Anouvong', 'lo' => 'ອະນຸວົງ'], 'at' => [18.9057, 103.0920]],
        'bolikhamxay' => ['en' => 'Bolikhamxay', 'lo' => 'ບໍລິຄຳໄຊ', 'town' => ['en' => 'Paksan', 'lo' => 'ປາກຊັນ'], 'at' => [18.3833, 103.6667]],
        'khammouane' => ['en' => 'Khammouane', 'lo' => 'ຄຳມ່ວນ', 'town' => ['en' => 'Thakhek', 'lo' => 'ທ່າແຂກ'], 'at' => [17.4104, 104.8307]],
        'savannakhet' => ['en' => 'Savannakhet', 'lo' => 'ສະຫວັນນະເຂດ', 'town' => ['en' => 'Savannakhet', 'lo' => 'ສະຫວັນນະເຂດ'], 'at' => [16.5560, 104.7529]],
        'salavan' => ['en' => 'Salavan', 'lo' => 'ສາລະວັນ', 'town' => ['en' => 'Salavan', 'lo' => 'ສາລະວັນ'], 'at' => [15.7167, 106.4167]],
        'sekong' => ['en' => 'Sekong', 'lo' => 'ເຊກອງ', 'town' => ['en' => 'Sekong', 'lo' => 'ເຊກອງ'], 'at' => [15.3460, 106.7210]],
        'champasak' => ['en' => 'Champasak', 'lo' => 'ຈຳປາສັກ', 'town' => ['en' => 'Pakse', 'lo' => 'ປາກເຊ'], 'at' => [15.1202, 105.7988]],
        'attapeu' => ['en' => 'Attapeu', 'lo' => 'ອັດຕະປື', 'town' => ['en' => 'Attapeu', 'lo' => 'ອັດຕະປື'], 'at' => [14.8090, 106.8317]],
    ];

    /** The towns always shown, in this order. */
    const MAIN = ['vientiane-capital', 'luang-prabang', 'xieng-khouang', 'champasak'];

    /**
     * The readings to show, ['time' => '2026-11-21T21:30', 'places' => [slug => [the reading, with 'aqi' while that is
     * up to date]]], or null while there are none up to date. With none at all (a new site, a cleared cache), they're
     * fetched after this page has been sent, at most once every ten minutes.
     */
    public static function current(): ?array
    {
        $stored = Cache::get(self::CACHE_KEY);
        if (! is_array($stored)) {
            if (Cache::add(self::CACHE_KEY.':trying', true, now()->addMinutes(10))) {
                defer(fn () => self::refresh());
            }

            return null;
        }

        if (! self::fresh($stored['weather']['time'] ?? null)) {
            return null;
        }

        $air = self::fresh($stored['air']['time'] ?? null) ? $stored['air']['places'] : [];
        $places = [];
        foreach ($stored['weather']['places'] as $slug => $reading) {
            $places[$slug] = [...$reading, 'aqi' => $air[$slug]['aqi'] ?? null];
        }

        return ['time' => $stored['weather']['time'], 'places' => $places];
    }

    /**
     * Fetches the latest weather and air quality and keeps them: ['weather' => […] or null, 'air' => […] or null] with
     * what was fetched. Where a fetch failed, the last readings of that kind stay.
     */
    public static function refresh(): array
    {
        $fetched = ['weather' => self::weather(), 'air' => self::air()];

        Cache::forever(self::CACHE_KEY, array_merge(Cache::get(self::CACHE_KEY, []), array_filter($fetched)));

        return $fetched;
    }

    /** Today's date in Laos. */
    public static function today(): string
    {
        return now(self::TIMEZONE)->toDateString();
    }

    /**
     * Each town now, and the next days: ['time' => …, 'places' => [slug => ['temp' => 24, 'code' => 0, 'day' => true,
     * 'days' => ['2026-11-21' => ['code' => 3, 'max' => 29, 'min' => 20, 'rain' => 10], …]]]].
     */
    private static function weather(): ?array
    {
        $answers = self::fetch(self::FORECAST, [
            'current' => 'temperature_2m,weather_code,is_day',
            'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max',
            'forecast_days' => 3,
        ]);
        if (! $answers) {
            return null;
        }

        $places = [];
        foreach (array_keys(self::PLACES) as $i => $slug) {
            $now = $answers[$i]['current'] ?? [];
            $daily = $answers[$i]['daily'] ?? [];
            if (! is_numeric($now['temperature_2m'] ?? null) || ! is_numeric($now['weather_code'] ?? null)) {
                return null;
            }

            $days = [];
            foreach ((array) ($daily['time'] ?? []) as $d => $date) {
                if (is_string($date) && is_numeric($daily['temperature_2m_max'][$d] ?? null) && is_numeric($daily['temperature_2m_min'][$d] ?? null)) {
                    $days[$date] = [
                        'code' => (int) ($daily['weather_code'][$d] ?? $now['weather_code']),
                        'max' => (int) round($daily['temperature_2m_max'][$d]),
                        'min' => (int) round($daily['temperature_2m_min'][$d]),
                        'rain' => is_numeric($daily['precipitation_probability_max'][$d] ?? null) ? (int) $daily['precipitation_probability_max'][$d] : null,
                    ];
                }
            }

            $places[$slug] = [
                'temp' => (int) round($now['temperature_2m']),
                'code' => (int) $now['weather_code'],
                'day' => (bool) ($now['is_day'] ?? true),
                'days' => $days,
            ];
        }

        return ['time' => self::time($answers), 'places' => $places];
    }

    /** Each town's air quality now: ['time' => …, 'places' => [slug => ['aqi' => 54 (US AQI), 'pm25' => 12.7]]]. */
    private static function air(): ?array
    {
        $answers = self::fetch(self::AIR, ['current' => 'us_aqi,pm2_5']);
        if (! $answers) {
            return null;
        }

        $places = [];
        foreach (array_keys(self::PLACES) as $i => $slug) {
            $now = $answers[$i]['current'] ?? [];
            if (is_numeric($now['us_aqi'] ?? null)) {
                $places[$slug] = ['aqi' => (int) round($now['us_aqi']), 'pm25' => is_numeric($now['pm2_5'] ?? null) ? round((float) $now['pm2_5'], 1) : null];
            }
        }

        return $places ? ['time' => self::time($answers), 'places' => $places] : null;
    }

    /** Open-Meteo's answers for every town, in PLACES's order, or null. */
    private static function fetch(string $url, array $query): ?array
    {
        $at = array_column(self::PLACES, 'at');

        try {
            $answers = Http::timeout(15)->acceptJson()->get($url, [
                'latitude' => implode(',', array_column($at, 0)),
                'longitude' => implode(',', array_column($at, 1)),
                'timezone' => self::TIMEZONE,
                ...$query,
            ])->throw()->json();
        } catch (Throwable) {
            return null;
        }

        return is_array($answers) && array_is_list($answers) && count($answers) === count(self::PLACES) ? $answers : null;
    }

    /** The readings' time, in Lao time ("2026-11-21T21:30"): the first town's, else now. */
    private static function time(array $answers): string
    {
        $time = $answers[0]['current']['time'] ?? null;

        return is_string($time) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $time) === 1
            ? $time
            : now(self::TIMEZONE)->format('Y-m-d\TH:i');
    }

    private static function fresh(?string $time): bool
    {
        return $time !== null
            && CarbonImmutable::parse($time, self::TIMEZONE)->diffInMinutes(now(), absolute: true) <= self::MAX_AGE_HOURS * 60;
    }
}
