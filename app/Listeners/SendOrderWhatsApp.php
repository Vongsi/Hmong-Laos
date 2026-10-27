<?php

namespace App\Listeners;

use App\Shop\WhatsApp;
use Statamic\Events\EntrySaved;
use Statamic\Events\EntrySaving;

/**
 * Sends the buyer a WhatsApp update from the shop's number (App\Shop\WhatsApp) when their order changes, whoever
 * changes it (the team in the control panel, BCEL One, the buyer cancelling): a new order, Confirmed, Paid, payment
 * Not accepted, Sent / ready to pick up, Completed, Cancelled. Several in one save go in one message. Compared with
 * the order as it is in the database, because the control panel hands over the order already changed. Each update
 * goes once per order (setting a status back and forth doesn't repeat it), except "Not accepted", which goes each
 * time the team turns down a new payment.
 */
class SendOrderWhatsApp
{
    /** @var array<int, list<string>> updates per order being saved, by object (a new order has no id yet) */
    private static array $pending = [];

    public function saving(EntrySaving $event): void
    {
        $order = $event->entry;
        if ($order->collectionHandle() !== 'orders' || ! WhatsApp::active()) {
            return;
        }

        $before = $order->id() ? self::stored($order->id()) : null;
        $events = [];
        if ($before === null) {
            $events[] = 'received';
        } else {
            $status = $order->get('status');
            if ($status !== ($before['status'] ?? null) && in_array($status, ['confirmed', 'shipped', 'completed', 'cancelled'], true)) {
                $events[] = $status;
            }
            $payment = $order->get('payment_status');
            if ($payment !== ($before['payment_status'] ?? null) && in_array($payment, ['paid', 'rejected'], true)) {
                $events[] = $payment;
            }
        }

        $sent = WhatsApp::sent($order);
        $events = array_values(array_filter($events, fn (string $event) => $event === 'rejected' || ! in_array($event, $sent, true)));
        if ($events) {
            self::$pending[spl_object_id($order)] = $events;
        }
    }

    public function saved(EntrySaved $event): void
    {
        $key = spl_object_id($event->entry);
        if (! isset(self::$pending[$key])) {
            return;
        }
        $events = self::$pending[$key];
        unset(self::$pending[$key]);

        WhatsApp::notify($event->entry, $events);
    }

    /** The order's values as saved last time, or null for a new order. */
    private static function stored(string $id): ?array
    {
        $model = config('statamic.eloquent-driver.entries.model');

        return $model::query()->find($id)?->data;
    }
}
