<?php

namespace App\Widgets;

use App\Appearance\Background;
use App\Shop\Shop;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;
use Statamic\Widgets\Widget;

/**
 * Dashboard: cash the shop received (at pick-up, or collected by the courier) that hasn't reached it yet, by who
 * has it. Tick "Cash handed in" on the payment once it has (App\Listeners\TrackCashHandIn).
 */
class CashOnHand extends Widget
{
    public function html()
    {
        $cash = Entry::query()->where('collection', 'payments')->where('payment_type', 'cash')->where('result', 'paid')->get()
            ->reject(fn (EntryContract $payment) => (bool) $payment->get('handed_in'))
            ->sortBy(fn (EntryContract $payment) => $payment->date()?->timestamp);

        return view('widgets.cash-on-hand', [
            'total' => Shop::kip($cash->sum(fn (EntryContract $payment) => (int) $payment->get('amount'))),
            'people' => $cash->groupBy(fn (EntryContract $payment) => (string) ($payment->get('cash_with') ?: 'Unknown'))
                ->map(fn ($payments, $who) => (object) [
                    'who' => $who,
                    'amount' => Shop::kip($payments->sum(fn (EntryContract $payment) => (int) $payment->get('amount'))),
                    'payments' => $payments->map(fn (EntryContract $payment) => (object) [
                        'title' => (string) $payment->get('title'),
                        'since' => $payment->date()?->copy()->setTimezone(Background::TIMEZONE)->format('j M'),
                        'url' => $payment->editUrl(),
                    ])->values(),
                ])->sortBy('who')->values(),
        ]);
    }
}
