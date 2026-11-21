<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Weather;
use App\Tags\Weather as WeatherTag;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WeatherTest extends TestCase
{
    /** The services that fail: 'weather', 'air'. */
    private array $down = [];

    /** How many towns Open-Meteo answers for (all of them unless set). */
    private ?int $towns = null;

    protected function setUp(): void
    {
        parent::setUp();
        // 22:00 in Laos.
        $this->travelTo('2026-11-21 15:00:00');
        $this->openMeteo();
    }

    /**
     * Answers like Open-Meteo's, a town each in Weather::PLACES's order: the town's number plus 20.4 degrees now, a clear
     * night in Vientiane and drizzle elsewhere; the air quality index is 40 plus the town's number. Http::fake's first
     * stub wins, so tests change $down and $towns rather than faking again.
     */
    private function openMeteo(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $air = str_contains($request->url(), 'air-quality');
            if (in_array($air ? 'air' : 'weather', $this->down, true)) {
                return Http::response(['error' => true, 'reason' => 'down'], 503);
            }

            $answers = [];
            foreach (range(0, ($this->towns ?? count(Weather::PLACES)) - 1) as $i) {
                $answers[] = $air
                    ? ['current' => ['time' => '2026-11-21T21:45', 'us_aqi' => 40 + $i, 'pm2_5' => 12.74]]
                    : [
                        'current' => ['time' => '2026-11-21T21:45', 'temperature_2m' => 20.4 + $i, 'weather_code' => $i === 0 ? 0 : 53, 'is_day' => 0],
                        'daily' => [
                            'time' => ['2026-11-21', '2026-11-22', '2026-11-23'],
                            'weather_code' => [1, 63, 95],
                            'temperature_2m_max' => [29.6, 28.2, 27.0],
                            'temperature_2m_min' => [19.5, 20.3, 21.4],
                            'precipitation_probability_max' => [5, 80, null],
                        ],
                    ];
            }

            return Http::response($answers);
        });
    }

    public function test_every_province_is_fetched_in_one_request_of_each_kind(): void
    {
        $fetched = Weather::refresh();

        $this->assertCount(18, $fetched['weather']['places']);
        $this->assertSame('2026-11-21T21:45', $fetched['weather']['time']);
        $this->assertSame([
            'temp' => 20,
            'code' => 0,
            'day' => false,
            'days' => [
                '2026-11-21' => ['code' => 1, 'max' => 30, 'min' => 20, 'rain' => 5],
                '2026-11-22' => ['code' => 63, 'max' => 28, 'min' => 20, 'rain' => 80],
                '2026-11-23' => ['code' => 95, 'max' => 27, 'min' => 21, 'rain' => null],
            ],
        ], $fetched['weather']['places']['vientiane-capital']);
        $this->assertSame(['aqi' => 57, 'pm25' => 12.7], $fetched['air']['places']['attapeu']);

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => count(explode(',', $request['latitude'])) === 18
            && count(explode(',', $request['longitude'])) === 18
            && $request['timezone'] === 'Asia/Vientiane');
    }

    public function test_readings_show_while_up_to_date(): void
    {
        Weather::refresh();

        $current = Weather::current();
        $this->assertSame(40, $current['places']['vientiane-capital']['aqi']);
        $this->assertSame(37, $current['places']['attapeu']['temp']);

        // Three hours and a quarter after the readings: "now" would be wrong.
        $this->travelTo('2026-11-21 18:00:00');
        $this->assertNull(Weather::current());
    }

    public function test_a_failed_fetch_leaves_the_last_readings_of_that_kind_up(): void
    {
        Weather::refresh();

        $this->down = ['air'];
        $fetched = Weather::refresh();

        $this->assertNull($fetched['air']);
        $this->assertNotNull($fetched['weather']);
        $this->assertSame(40, Cache::get(Weather::CACHE_KEY)['air']['places']['vientiane-capital']['aqi']);
    }

    public function test_an_answer_for_the_wrong_number_of_towns_is_not_kept(): void
    {
        $this->towns = 1;

        $this->assertSame(['weather' => null, 'air' => null], Weather::refresh());
        $this->assertSame([], Cache::get(Weather::CACHE_KEY));
    }

    public function test_without_readings_the_first_visit_fetches_them_once(): void
    {
        $this->down = ['weather', 'air'];

        $this->assertNull(Weather::current());
        $this->assertNull(Weather::current());
        $this->assertTrue(Cache::has(Weather::CACHE_KEY.':trying'));
    }

    public function test_the_page_gets_the_main_towns_then_the_provinces_with_the_members_own(): void
    {
        Weather::refresh();
        $member = new User;
        $member->forceFill(['province' => 'Xieng Khouang', 'country' => 'LAO']);
        $this->actingAs($member);

        $page = (new WeatherTag)->index();

        $this->assertSame(['Vientiane', 'Luang Prabang', 'Phonsavan', 'Pakse'], array_column(array_slice($page['places'], 0, 4), 'name'));
        $this->assertSame([
            'slug' => 'vientiane-capital', 'main' => true, 'name' => 'Vientiane', 'icon' => 'moon', 'label' => __('site.weather.kinds.clear'),
            'temp' => '20°', 'high_low' => '30°/20°', 'rain' => '5%', 'aqi' => '40', 'aqi_level' => 'good', 'aqi_label' => __('site.weather.air.good'),
        ], $page['places'][0]);
        $houaphanh = collect($page['places'])->firstWhere('slug', 'houaphanh');
        $this->assertFalse($houaphanh['main']);
        $this->assertSame('Houaphanh', $houaphanh['name']);
        $this->assertSame('drizzle', $houaphanh['icon']);
        $this->assertCount(18, $page['places']);
        $this->assertCount(18, $page['provinces']);
        $this->assertSame('Attapeu', $page['provinces'][0]['name']);
        $this->assertSame('21:45', $page['read_at']);
        $this->assertSame('xieng-khouang', $page['member_place']);
    }

    public function test_weather_codes_and_air_levels(): void
    {
        $this->assertSame(['clear', 'clear', 'partly', 'cloudy', 'fog', 'drizzle', 'rain', 'heavy', 'rain', 'heavy', 'storm'],
            array_map(fn ($code) => WeatherTag::kind($code), [0, 1, 2, 3, 45, 55, 61, 65, 80, 82, 95]));
        $this->assertSame(['good', 'moderate', 'sensitive', 'unhealthy', 'very', 'hazardous'],
            array_map(fn ($aqi) => WeatherTag::airLevel($aqi), [50, 51, 150, 151, 300, 301]));
    }

    public function test_the_command_reports_both_kinds(): void
    {
        $this->down = ['air'];

        $this->artisan('weather:update')
            ->expectsOutput('Weather of 18 towns at 2026-11-21T21:45 Lao time (Vientiane 20°C).')
            ->expectsOutput('Could not get the air quality; the last readings stay up for up to 3 hours.')
            ->assertExitCode(1);
    }
}
