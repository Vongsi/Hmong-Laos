<?php

namespace App\Widgets;

use App\Models\User;
use App\Shop\SellerPayouts as Payouts;
use App\Shop\Shop;
use Statamic\Facades\Entry;
use Statamic\Widgets\Widget;

/**
 * Dashboard: what the shop owes members for their products in delivered, paid orders, with a button per seller
 * to prepare the payout, and the payouts prepared but not yet paid (App\Shop\SellerPayouts).
 */
class SellerPayouts extends Widget
{
    public function html()
    {
        $owed = Payouts::owed()->map(function (array $owed) {
            $user = User::find($owed['seller']);

            return (object) [
                'seller' => $owed['seller'],
                'name' => $user?->name ?? 'Member '.$owed['seller'],
                'userUrl' => $user ? cp_route('users.edit', $user->id) : null,
                'payTo' => Payouts::payTo($user),
                'items' => count($owed['lines']),
                'amount' => Shop::kip($owed['total']),
            ];
        })->sortBy('name')->values();

        $me = (string) auth()->id();
        $open = Entry::query()->where('collection', Payouts::COLLECTION)->whereIn('payout_status', ['to_pay', 'sent'])->get()
            ->map(fn ($payout) => (object) [
                'status' => (string) $payout->get('payout_status'),
                'title' => (string) $payout->get('title'),
                'url' => $payout->editUrl(),
                'madeBy' => (string) $payout->get('made_by'),
                'byMe' => $me !== '' && $me === (string) $payout->get('made_by_id'),
                'reference' => (string) $payout->get('reference'),
                'checkerNote' => (string) $payout->get('checker_note'),
            ]);

        return view('widgets.seller-payouts', [
            'owed' => $owed,
            'toPay' => $open->where('status', 'to_pay')->values(),
            'toCheck' => $open->where('status', 'sent')->values(),
            'fee' => Payouts::fee(),
            'listUrl' => cp_route('collections.show', Payouts::COLLECTION),
        ]);
    }
}
