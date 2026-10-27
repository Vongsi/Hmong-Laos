<?php

namespace App\Console\Commands;

use App\Shop\OnePay;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Statamic\Facades\Entry;

/**
 * For testing without paying: sends OnePay::paid() the kind of message BCEL sends when an order's QR code
 * is paid, as `onepay:listen` would. Only with the test merchant (ONEPAY_TEST=true).
 */
class SimulateOnePayPayment extends Command
{
    protected $signature = 'onepay:simulate {order : The order number, e.g. DAH-261026-ABCD} {--amount= : Pay a different amount (to try a mismatch)}';

    protected $description = 'Pretend an order was paid with BCEL One (test merchant only)';

    public function handle(): int
    {
        if (! OnePay::testing()) {
            $this->error('Only with the BCEL test merchant (ONEPAY_TEST=true).');

            return self::FAILURE;
        }

        $order = Entry::query()->where('collection', 'orders')->where('title', $this->argument('order'))->first();
        if (! $order || ! $order->get('onepay_ref')) {
            $this->error('No order with that number waiting for a BCEL One payment.');

            return self::FAILURE;
        }

        // Shaped like BCEL's real message.
        $result = OnePay::paid([
            'id' => random_int(100000000, 999999999),
            'service' => 'ONEPAY',
            'mcid' => config('onepay.merchant_id'),
            'iid' => $order->get('title'),
            'uuid' => $order->get('onepay_ref'),
            'amount' => (int) ($this->option('amount') ?? OnePay::amountDue($order)),
            'ccy' => 'LAK',
            'ticket' => 'TEST'.Str::upper(Str::random(8)),
            'fccref' => (string) random_int(100000000000000, 999999999999999),
            'name' => 'TEST PAYER',
            'phone' => '20000000',
            'description' => 'Order '.$order->get('title'),
            'txtime' => now()->setTimezone('Asia/Vientiane')->format('d/m/Y H:i:s'),
            'simulated' => true,
        ]);
        $this->info($result);

        return $result === 'paid' ? self::SUCCESS : self::FAILURE;
    }
}
