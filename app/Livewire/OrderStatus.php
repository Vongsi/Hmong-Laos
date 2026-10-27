<?php

namespace App\Livewire;

use App\Appearance\Background;
use App\Shop\OnePay;
use App\Shop\Payments;
use App\Shop\PlaceOrder;
use App\Shop\Receipts;
use App\Shop\Shop;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Asset;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

/** The customer's order page. Anyone with the link (number + secret token) can see it. */
class OrderStatus extends Component
{
    use WithFileUploads;

    #[Locked]
    public string $number = '';

    #[Locked]
    public string $token = '';

    #[Locked]
    public string $site;

    public string $paymentNote = '';

    /** The receipt the customer adds for a bank transfer (App\Shop\Receipts). */
    public $receipt = null;

    /** "Cancel order": asked once more before it happens, with an optional reason. */
    public bool $confirmingCancel = false;

    public string $cancelReason = '';

    /** "Find your order", when the page is opened without its link. */
    public string $findNumber = '';

    public string $findPhone = '';

    /** $n and $t come from the link (?n=…&t=…) and may be missing. */
    public function mount(?string $n = null, ?string $t = null): void
    {
        $this->number = (string) $n;
        $this->token = (string) $t;
        $this->site = Site::current()->handle();
    }

    /**
     * "Find your order": the order number and the phone number it was placed with open its page (the secret link).
     * Limited per order and per network address, so neither can be guessed; many phones in Laos share an address,
     * so that limit is generous.
     */
    public function find(): void
    {
        $this->validate(
            ['findNumber' => ['required', 'string', 'max:40'], 'findPhone' => ['required', 'string', 'max:40']],
            ['findNumber.*' => __('site.shop.required'), 'findPhone.*' => __('site.shop.required')],
        );

        $number = strtoupper(preg_replace('/\s+/', '', $this->findNumber));
        $byOrder = 'order-find:'.$number;
        $byAddress = 'order-find-ip:'.request()->ip();
        if (RateLimiter::tooManyAttempts($byOrder, 5) || RateLimiter::tooManyAttempts($byAddress, 30)) {
            $this->addError('findNumber', __('site.shop.find_too_many'));

            return;
        }
        RateLimiter::hit($byOrder, 600);
        RateLimiter::hit($byAddress, 600);

        // The last 8 digits, so "020 5555 1234", "02055551234" and "+856 20 5555 1234" all match.
        $digits = fn ($phone) => substr(preg_replace('/\D/', '', (string) $phone), -8);
        $order = Entry::query()->where('collection', 'orders')->where('title', $number)->first();
        if (! $order || strlen($digits($this->findPhone)) < 6 || ! hash_equals($digits($order->get('customer_phone')), $digits($this->findPhone))) {
            $this->addError('findNumber', __('site.shop.find_not_found'));

            return;
        }

        RateLimiter::clear($byOrder);
        $this->redirect(Shop::orderUrl($this->site, $order));
    }

    /**
     * The customer cancels their order while it waits for payment. The listener puts the items back in stock;
     * the team is emailed. Not once they reported a payment or it was paid: then the team has to check first.
     */
    public function cancelOrder(): void
    {
        $order = $this->order();
        abort_unless($order && self::canCancel($order), 403);
        $this->validate(['cancelReason' => ['nullable', 'string', 'max:300']], ['cancelReason.*' => __('site.shop.invalid')]);

        $reason = trim($this->cancelReason);
        $order->set('status', 'cancelled')
            ->set('cancel_note', 'Cancelled by the customer on '.now()->setTimezone(Background::TIMEZONE)->format('d/m/Y H:i').($reason !== '' ? ".\nReason: ".$reason : '.'))
            ->save();
        PlaceOrder::notifyTeam($order, 'Order '.$order->get('title').' cancelled by the customer');
        $this->reset('confirmingCancel', 'cancelReason');
    }

    /** While it waits for payment (or the team didn't accept it) and before it is sent. */
    public static function canCancel(EntryContract $order): bool
    {
        return in_array($order->get('status'), ['new', 'confirmed'], true)
            && in_array($order->get('payment_status'), ['pending', 'rejected'], true);
    }

    /** Checks the picture as soon as it is chosen. */
    public function updatedReceipt(): void
    {
        $this->validateOnly('receipt', $this->paymentRules(), $this->paymentMessages());
    }

    /**
     * "I've paid": with the receipt from their banking app, a message (time or transaction number), or both.
     * A receipt is kept with the order and checked once the page has answered (App\Shop\Receipts::process()),
     * which also emails the team.
     */
    public function reportPayment(): void
    {
        $order = $this->order();
        abort_unless($order && self::awaitingPayment($order), 403);

        $this->validate($this->paymentRules(), $this->paymentMessages());

        $receipt = $this->receipt ? Receipts::store($order, $this->receipt) : null;
        Payments::addHistory($order, 'reported', ['note' => trim($this->paymentNote), 'receipt' => $receipt?->path()]);
        // After "Not accepted", the team's message has been answered; a new "Not accepted" will tell them again.
        $order->set('payment_status', 'reported')
            ->set('payment_note', trim($this->paymentNote) ?: null)
            ->set('receipt', $receipt ? [$receipt->path()] : null)
            ->set('receipt_check', null)
            ->set('payment_reply', null)
            ->set('payment_rejected_at', null)
            ->save();

        if ($receipt) {
            $orderId = $order->id();
            $receiptId = $receipt->id();
            dispatch(fn () => Receipts::process($orderId, $receiptId))->afterResponse();
        } else {
            PlaceOrder::notifyTeam($order, 'Payment reported for order '.$order->get('title'));
        }
        $this->reset('paymentNote', 'receipt');
    }

    /**
     * What the customer reported and what the team answered, oldest first, with links to their own receipts.
     * Only once there is more to it than "Paid" (that is already in the timeline).
     *
     * @return list<array{time: string, event: string, note: ?string, receipt: ?string}>
     */
    private function history(EntryContract $order): array
    {
        $history = Payments::history($order);
        if (! array_filter($history, fn (array $step) => $step['event'] !== 'paid')) {
            return [];
        }

        return array_map(fn (array $step) => [
            'time' => Carbon::parse($step['at'], 'UTC')->setTimezone(Background::TIMEZONE)->translatedFormat('j M, H:i'),
            'event' => $step['event'],
            'note' => $step['note'] ?? null,
            'receipt' => isset($step['receipt'])
                ? route('order.receipt', ['n' => $order->get('title'), 't' => $order->get('token'), 'f' => basename($step['receipt'])])
                : null,
        ], $history);
    }

    /** Not paid yet, or the team didn't accept the payment: the customer can pay or report again. */
    public static function awaitingPayment(EntryContract $order): bool
    {
        return in_array($order->get('payment_status'), ['pending', 'rejected'], true) && $order->get('status') !== 'cancelled';
    }

    private function paymentRules(): array
    {
        return [
            'receipt' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'paymentNote' => ['nullable', 'string', 'max:500', 'required_without:receipt'],
        ];
    }

    private function paymentMessages(): array
    {
        return [
            'receipt.*' => __('site.shop.receipt_invalid'),
            'paymentNote.required_without' => __('site.shop.receipt_or_note'),
            'paymentNote.max' => __('site.shop.invalid'),
        ];
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

        // BCEL One: the order's QR code while it waits for payment.
        $onepay = $order && $order->get('payment_method') === 'onepay' && OnePay::configured() && self::awaitingPayment($order)
            ? OnePay::qr($order)
            : null;

        return view('livewire.order-status', [
            'order' => $order,
            'onepay' => $onepay,
            'onepayTest' => OnePay::testing(),
            'onepayDue' => $order ? OnePay::amountDue($order) : 0,
            'qrUrl' => $qr ? Asset::find('assets::'.$qr)?->url() : null,
            'bank' => [
                'bank' => Shop::setting($this->site, 'bank_name'),
                'account_name' => Shop::setting($this->site, 'account_name'),
                'account_number' => Shop::setting($this->site, 'account_number'),
            ],
            'instructions' => Shop::setting($this->site, 'payment_instructions'),
            'readingReceipts' => Receipts::reading(),
            'productsUrl' => Shop::url($this->site, 'products'),
            'timeline' => $order ? $this->timeline($order) : [],
            'history' => $order ? $this->history($order) : [],
            // Back to the member's own order list, or to "Find your order" (which lists the orders opened on this phone).
            'back' => $order && Auth::check() && in_array(Auth::id(), (array) $order->get('customer'))
                ? ['url' => Shop::url($this->site, 'account/orders'), 'label' => __('site.shop.my_orders')]
                : ['url' => Shop::url($this->site, 'order'), 'label' => __('site.shop.find_link')],
            'courier' => $order ? Shop::courier($order) : null,
            'pickupPlace' => Shop::setting($this->site, 'pickup_place'),
        ]);
    }
}
