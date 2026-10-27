<?php

namespace App\Listeners;

use App\Mail\PaymentNotAccepted;
use App\Shop\OrderMessages;
use App\Shop\Payments;
use App\Shop\PlaceOrder;
use App\Shop\Shop;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Statamic\Events\EntrySaved;
use Statamic\Events\EntrySaving;
use Statamic\Facades\Site;
use Statamic\Facades\User;
use Throwable;

/**
 * Follows an order's payment as the team updates it in the control panel, and keeps its payment history
 * (App\Shop\Payments::addHistory()).
 * - Paid: the payments it was checked with count as paid in Payments, or a line is added for cash or a transfer
 *   confirmed by hand, with who marked it paid (App\Shop\Payments::confirm()).
 * - Refunded: its payments in Payments say so.
 * - Not accepted: the customer is told, in their language, by email (if they gave one) and on their order page,
 *   where they can pay or add a receipt again; the team gets a ready-made WhatsApp message for them, and the
 *   receipt's payment becomes "Not accepted" with the team's message. Once each time: a marker that isn't in
 *   the blueprint is kept, and cleared when the customer reports a payment again (App\Livewire\OrderStatus).
 */
class TrackOrderPayment
{
    /** @var array<string, true> orders to announce once their save has gone through */
    private static array $notAccepted = [];

    public function saving(EntrySaving $event): void
    {
        $order = $event->entry;
        if ($order->collectionHandle() !== 'orders') {
            return;
        }

        if ($order->get('payment_status') === 'rejected' && ! $order->get('payment_rejected_at')) {
            $order->set('payment_rejected_at', now()->format('Y-m-d H:i'));
            Payments::addHistory($order, 'rejected', ['note' => trim((string) $order->get('payment_reply'))]);
            self::$notAccepted[$order->id()] = true;
        }
        $history = Payments::history($order);
        $last = end($history)['event'] ?? null;
        if ($order->get('payment_status') === 'paid' && $last !== 'paid') {
            Payments::addHistory($order, 'paid');
        }
        if ($order->get('payment_status') === 'refunded' && $last !== 'refunded') {
            Payments::addHistory($order, 'refunded');
        }
    }

    public function saved(EntrySaved $event): void
    {
        $order = $event->entry;
        if ($order->collectionHandle() !== 'orders') {
            return;
        }
        // Paid: its payments count as paid, or a line is added for cash or a transfer confirmed by hand. Refunded:
        // its payments say so.
        if ($order->get('payment_status') === 'paid') {
            Payments::confirm($order, User::current()?->name());
        } elseif ($order->get('payment_status') === 'refunded') {
            Payments::refund($order);
        }
        if (! isset(self::$notAccepted[$order->id()])) {
            return;
        }
        unset(self::$notAccepted[$order->id()]);

        $reason = trim((string) $order->get('payment_reply'));
        Payments::rejectReceipts($order, $reason ?: null);

        $site = $order->get('customer_site') ?: 'hm';
        $lang = Site::get($site)?->lang() ?? 'en';
        $params = [
            'name' => $order->get('customer_name'),
            'number' => $order->get('title'),
            'message' => $reason,
            'url' => Shop::orderUrl($site, $order),
        ];

        if ($email = $order->get('customer_email')) {
            try {
                Mail::to($email)->locale($lang)->send(new PaymentNotAccepted($order, $params));
            } catch (Throwable $e) {
                Log::error("Shop: could not email the customer that the payment for order {$params['number']} was not accepted: {$e->getMessage()}");
            }
        }

        PlaceOrder::notifyTeam(
            $order,
            "Order {$params['number']}: payment not accepted".($email ? ', customer emailed' : ''),
            Shop::whatsappUrl($order->get('customer_phone'), OrderMessages::text($order, ['rejected'])),
        );
    }
}
