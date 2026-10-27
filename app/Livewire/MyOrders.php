<?php

namespace App\Livewire;

use App\Appearance\Background;
use App\Shop\Shop;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

/**
 * "My orders": the orders the member placed while signed in (PlaceOrder keeps them as the order's customer),
 * newest first, each opening its order page with the payment history. Orders still to pay say so, with a
 * "Pay now". On My account the latest few ("overview"); the rest on its own page, a page at a time ("all",
 * /account/orders).
 */
class MyOrders extends Component
{
    use WithPagination;

    const FIRST = 5;

    const PER_PAGE = 10;

    /** overview (My account) or all (/account/orders) */
    #[Locked]
    public string $mode = 'overview';

    public function render()
    {
        $site = Site::current()->handle();
        $query = Entry::query()->where('collection', 'orders')->whereJsonContains('customer', Auth::id())->orderBy('date', 'desc');
        $all = $this->mode === 'all';
        $page = Auth::check() && $all ? $query->paginate(self::PER_PAGE) : null;
        $latest = Auth::check() && ! $all ? $query->limit(self::FIRST)->get() : collect();

        return view('livewire.my-orders', [
            'signedIn' => Auth::check(),
            'all' => $all,
            'page' => $page,
            'count' => Auth::check() ? ($page ? $page->total() : Entry::query()->where('collection', 'orders')->whereJsonContains('customer', Auth::id())->count()) : 0,
            'orders' => collect($page ? $page->items() : $latest)->map(fn (EntryContract $order) => $this->line($order, $site))->all(),
            'allUrl' => Shop::url($site, 'account/orders'),
            'shopUrl' => Shop::url($site, 'products'),
        ]);
    }

    /** What one row shows. */
    private function line(EntryContract $order, string $site): array
    {
        $items = (array) $order->get('items');
        $payment = (string) $order->get('payment_status');
        $cancelled = $order->get('status') === 'cancelled';
        $toPay = ! $cancelled && in_array($payment, ['pending', 'rejected'], true) && $order->get('payment_method') !== 'cash';

        return [
            'title' => $order->get('title'),
            'line' => trim(($items[0]['product'] ?? '').(count($items) > 1 ? ' '.__('site.shop.items_more', ['count' => count($items) - 1]) : '')),
            'meta' => $order->date()->copy()->setTimezone(Background::TIMEZONE)->translatedFormat('j M Y').' · '.Shop::kip((int) $order->get('total')),
            // One coloured badge: the payment, or "Cancelled".
            'badge' => $cancelled ? __('site.shop.statuses.cancelled') : __('site.shop.payments_short.'.$payment),
            'tone' => $cancelled ? 'grey' : match ($payment) {
                'paid' => 'green',
                'reported' => 'blue',
                'rejected' => 'red',
                'refunded' => 'grey',
                default => $order->get('payment_method') === 'cash' ? 'grey' : 'amber',
            },
            'action' => $toPay ? __($payment === 'rejected' ? 'site.shop.check_payment_cta' : 'site.shop.pay_now_cta') : null,
            'note' => $cancelled ? null : __('site.shop.statuses.'.$order->get('status')),
            'url' => Shop::orderUrl($site, $order),
        ];
    }
}
