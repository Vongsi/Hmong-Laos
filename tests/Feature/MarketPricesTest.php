<?php

namespace Tests\Feature;

use App\Support\MarketPrices;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MarketPricesTest extends TestCase
{
    /**
     * Exchange API's files: an ounce of gold at 31,103,476.8 kip is 1,000,000 kip a gram, so 15,000,000 kip a baht
     * (15 g); a dollar is 22,500 kip.
     */
    private const RATES = [
        'xau' => ['date' => '2026-11-20', 'xau' => ['lak' => 31103476.8, 'usd' => 3550.43]],
        'xag' => ['date' => '2026-11-20', 'xag' => ['lak' => 943376.5, 'usd' => 41.927]],
        'usd' => ['date' => '2026-11-20', 'usd' => ['lak' => 22500, 'usd' => 1]],
    ];

    /** The oil files end with a day without a price, as on holidays. */
    private const OIL = [
        'brent' => "Date,Price\n2026-11-14,95.17\n2026-11-17,92.94\n2026-11-18,\n",
        'wti' => "observation_date,DCOILWTICO\n2026-11-14,91.96\n2026-11-17,89.53\n2026-11-18,.\n",
    ];

    /** Answers like the real sources; $down lists hosts that fail. */
    private function sources(array $down = []): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($down) {
            $url = $request->url();
            foreach ($down as $host) {
                if (str_contains($url, $host)) {
                    return Http::response('down', 503);
                }
            }
            if (preg_match('#/currencies/(\w+)\.json$#', $url, $m)) {
                return Http::response(self::RATES[$m[1]]);
            }

            return Http::response(self::OIL[str_contains($url, 'brent') || str_contains($url, 'BRENT') ? 'brent' : 'wti']);
        });
    }

    public function test_gold_silver_and_oil_are_kept_in_kip_and_dollars(): void
    {
        $this->sources();

        $fetched = MarketPrices::refresh();

        $this->assertSame([
            'date' => '2026-11-20',
            'gold' => ['baht' => 15000000, 'gram' => 1000000, 'ounce_usd' => '$3,550'],
            'silver' => ['baht' => 455000, 'gram' => 30300, 'ounce_usd' => '$41.93'],
        ], $fetched['metals']);
        // $92.94 a barrel at 22,500 kip a dollar, over 158.99 litres: 13,153 kip a litre.
        $this->assertSame([
            'date' => '2026-11-17',
            'brent' => ['barrel_usd' => '$92.94', 'litre' => 13200],
            'wti' => ['barrel_usd' => '$89.53', 'litre' => 12700],
        ], $fetched['oil']);
        $this->assertSame($fetched, Cache::get(MarketPrices::CACHE_KEY));
    }

    public function test_the_second_source_is_used_when_the_first_fails(): void
    {
        $this->sources(down: ['jsdelivr', 'githubusercontent']);

        $fetched = MarketPrices::refresh();

        $this->assertSame(15000000, $fetched['metals']['gold']['baht']);
        $this->assertSame('$92.94', $fetched['oil']['brent']['barrel_usd']);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'fred.stlouisfed.org'));
    }

    public function test_a_failed_fetch_leaves_the_last_prices_of_that_kind_up(): void
    {
        $lastOil = ['date' => now()->subDays(3)->toDateString(), 'brent' => ['barrel_usd' => '$80.00', 'litre' => 1], 'wti' => ['barrel_usd' => '$78.00', 'litre' => 1]];
        Cache::forever(MarketPrices::CACHE_KEY, ['oil' => $lastOil]);
        $this->sources(down: ['githubusercontent', 'fred.stlouisfed.org']);

        $fetched = MarketPrices::refresh();

        $this->assertNull($fetched['oil']);
        $this->assertSame($lastOil, Cache::get(MarketPrices::CACHE_KEY)['oil']);
        $this->assertSame(15000000, Cache::get(MarketPrices::CACHE_KEY)['metals']['gold']['baht']);
    }

    public function test_oil_is_shown_without_kip_when_the_dollar_rate_is_missing(): void
    {
        $this->sources(down: ['currencies/usd.json']);

        $this->assertSame(['barrel_usd' => '$92.94', 'litre' => null], MarketPrices::refresh()['oil']['brent']);
    }

    public function test_prices_that_stopped_updating_are_not_shown(): void
    {
        Cache::forever(MarketPrices::CACHE_KEY, [
            'metals' => ['date' => now()->subDays(MarketPrices::MAX_AGE_DAYS['metals'] + 1)->toDateString()],
            'oil' => ['date' => now()->subDays(10)->toDateString()],
        ]);
        $this->assertSame(['oil'], array_keys(MarketPrices::current()));

        Cache::forever(MarketPrices::CACHE_KEY, ['metals' => ['date' => '2026-01-01'], 'oil' => ['date' => '2026-01-01']]);
        $this->assertNull(MarketPrices::current());
    }

    public function test_the_command_fetches_and_reports_the_prices(): void
    {
        $this->sources();

        $this->artisan('market-prices:update')
            ->expectsOutput('Gold and silver of 2026-11-20: 15,000,000 ₭ and 455,000 ₭ per baht (15 g).')
            ->expectsOutput('Crude oil of 2026-11-17: Brent $92.94, WTI $89.53 per barrel.')
            ->assertSuccessful();
    }
}
