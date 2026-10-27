<?php

namespace App\Livewire;

use App\Models\User;
use App\Shop\SellerPayouts;
use App\Shop\Shop;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

/**
 * "My earnings" for members who sell: what the shop still owes them for delivered products, what it paid so far,
 * and each payout (App\Shop\SellerPayouts). On My account the latest few; all of them at /account/payouts.
 */
class MyEarnings extends Component
{
    use WithPagination;

    const FIRST = 5;

    const PER_PAGE = 10;

    /** overview (My account) or all (its own page) */
    #[Locked]
    public string $mode = 'overview';

    public function render()
    {
        $site = Site::current()->handle();
        $id = (string) Auth::id();
        $payouts = Auth::check() ? SellerPayouts::payouts($id) : collect();
        $sells = Auth::check() && ($payouts->isNotEmpty() || Entry::query()->where('collection', 'products')->whereJsonContains('submitted_by', $id)->exists());
        $owed = $sells ? SellerPayouts::owed($id)->get($id) : null;
        $preparing = $payouts->filter(fn (EntryContract $payout) => $payout->get('payout_status') !== 'paid');
        $all = $this->mode === 'all';
        $page = null;
        if ($all) {
            $current = max(1, $this->getPage());
            $page = new LengthAwarePaginator($payouts->forPage($current, self::PER_PAGE)->values(), $payouts->count(), self::PER_PAGE, $current);
        }

        return view('livewire.my-earnings', [
            'show' => $sells || $all,
            'all' => $all,
            'count' => $payouts->count(),
            'waiting' => ($owed['total'] ?? 0) + $preparing->sum(fn (EntryContract $payout) => (int) $payout->get('amount')),
            'waitingItems' => count($owed['lines'] ?? []) + $preparing->sum(fn (EntryContract $payout) => count((array) $payout->get('lines'))),
            'paid' => $payouts->filter(fn (EntryContract $payout) => $payout->get('payout_status') === 'paid')->sum(fn (EntryContract $payout) => (int) $payout->get('amount')),
            'fee' => SellerPayouts::fee(),
            'needsAccount' => $sells && ! filled(User::find($id)?->payout_account_number),
            'accountUrl' => Shop::url($site, 'account').'#payout',
            'page' => $page,
            'rows' => collect($page ? $page->items() : $payouts->take(self::FIRST))->map(fn (EntryContract $payout) => $this->row($payout, $site))->all(),
            'allUrl' => Shop::url($site, 'account/payouts'),
        ]);
    }

    /** One payout, in the shape of partials/account-row. */
    private function row(EntryContract $payout, string $site): array
    {
        $paid = $payout->get('payout_status') === 'paid';
        $items = count((array) $payout->get('lines'));

        return [
            'title' => Shop::kip((int) $payout->get('amount')),
            'line' => (filled($payout->get('payout_number')) ? $payout->get('payout_number').' · ' : '').trans_choice('site.payouts.items', $items, ['count' => $items])
                .($paid && filled($payout->get('reference')) ? ' · '.__('site.payouts.ref', ['ref' => $payout->get('reference')]) : ''),
            'meta' => $paid
                ? __('site.payouts.paid_on', ['date' => SellerPayouts::day($payout->get('paid_on') ?: $payout->date())])
                : __('site.payouts.prepared_on', ['date' => SellerPayouts::day($payout->date())]),
            'badge' => __($paid ? 'site.payouts.status_paid' : 'site.payouts.status_preparing'),
            'tone' => $paid ? 'green' : 'blue',
            'action' => null,
            'note' => filled($payout->get('note')) ? Str::limit((string) $payout->get('note'), 60) : null,
            'url' => Shop::url($site, 'account/payouts'),
        ];
    }
}
