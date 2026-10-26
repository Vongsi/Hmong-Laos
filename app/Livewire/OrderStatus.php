<?php

namespace App\Livewire;

use App\Appearance\Background;
use App\Shop\PlaceOrder;
use App\Shop\Shop;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Asset;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

/** The customer's order page. Anyone with the link (number + secret token) can see it. */
class OrderStatus extends Component
{
    #[Locked]
    public string $number = '';

    #[Locked]
    public string $token = '';

    #[Locked]
    public string $site;

    public string $paymentNote = '';

    /** $n and $t come from the link (?n=…&t=…) and may be missing. */
    public function mount(?string $n = null, ?string $t = null): void
    {
        $this->number = (string) $n;
        $this->token = (string) $t;
        $this->site = Site::current()->handle();
    }

    public function reportPayment(): void
    {
        $order = $this->order();
        abort_unless($order && $order->get('payment_status') === 'pending', 403);

        $this->validate(['paymentNote' => ['required', 'string', 'max:500']], [
            'paymentNote.required' => __('site.shop.required'),
            'paymentNote.max' => __('site.shop.invalid'),
        ]);

        $order->set('payment_status', 'reported')->set('payment_note', trim($this->paymentNote))->save();
        PlaceOrder::notifyTeam($order, 'Payment reported for order '.$order->get('title'));
        $this->paymentNote = '';
    }

    private function order(): ?EntryContract
    {
        if ($this->number === '' || $this->token === '') {
            return null;
        }

        $order = Entry::query()->where('collection', 'orders')->where('title', $this->number)->first();

        return $order && hash_equals((string) $order->get('token'), $this->token) ? $order : null;
    }

    /**
     * Received → Paid → Sent → Delivered, with Laos times. Cash orders are paid when they arrive,
     * so they skip the "Paid" step; pick-up orders say "Ready to pick up" and "Picked up".
     *
     * @return list<array{label: string, time: ?string, done: bool, current: bool}>
     */
    private function timeline(EntryContract $order): array
    {
        $pickup = $order->get('delivery') !== 'delivery';
        $status = $order->get('status');
        $time = fn ($value) => $value ? Carbon::parse($value, 'UTC')->setTimezone(Background::TIMEZONE)->translatedFormat('j M, H:i') : null;

        $steps = [[__('site.shop.step_received'), $time($order->date()), true]];
        if ($order->get('payment_method') !== 'cash') {
            $steps[] = [__('site.shop.step_paid'), $time($order->get('paid_at')), $order->get('payment_status') === 'paid'];
        }
        $steps[] = [__($pickup ? 'site.shop.step_ready' : 'site.shop.step_sent'), $time($order->get('shipped_at')), in_array($status, ['shipped', 'completed'], true)];
        $steps[] = [__($pickup ? 'site.shop.step_picked_up' : 'site.shop.step_delivered'), $time($order->get('delivered_at')), $status === 'completed'];

        // The step being worked on is the one after the last finished step.
        $lastDone = max(array_keys(array_filter(array_column($steps, 2))));

        return array_map(fn ($step, $i) => [
            'label' => $step[0],
            'time' => $step[2] ? $step[1] : null,
            'done' => $step[2],
            'current' => $i === $lastDone + 1,
        ], $steps, array_keys($steps));
    }

    public function render()
    {
        $order = $this->order();
        $qr = Shop::setting($this->site, 'qr_image');
        $qr = is_array($qr) ? ($qr[0] ?? null) : $qr;

        return view('livewire.order-status', [
            'order' => $order,
            'qrUrl' => $qr ? Asset::find('assets::'.$qr)?->url() : null,
            'bank' => [
                'bank' => Shop::setting($this->site, 'bank_name'),
                'account_name' => Shop::setting($this->site, 'account_name'),
                'account_number' => Shop::setting($this->site, 'account_number'),
            ],
            'instructions' => Shop::setting($this->site, 'payment_instructions'),
            'productsUrl' => Shop::url($this->site, 'products'),
            'timeline' => $order ? $this->timeline($order) : [],
            'courier' => $order ? Shop::courier($order) : null,
            'pickupPlace' => Shop::setting($this->site, 'pickup_place'),
        ]);
    }
}
