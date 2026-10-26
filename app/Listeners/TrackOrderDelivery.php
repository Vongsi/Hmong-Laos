<?php

namespace App\Listeners;

use App\Mail\OrderShipped;
use App\Shop\PlaceOrder;
use App\Shop\Shop;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Statamic\Events\EntrySaved;
use Statamic\Events\EntrySaving;
use Statamic\Facades\Site;
use Throwable;

/**
 * Follows an order's delivery as the team updates it in the control panel.
 * - Stamps when it was paid, sent and completed, for the customer's timeline.
 * - The first time it becomes "Sent / ready to pick up", emails the customer (if they gave an email)
 *   and emails the team a ready-made WhatsApp message for the customer. Only once: a marker that isn't
 *   in the blueprint is kept, because the control panel merges its values into the order.
 */
class TrackOrderDelivery
{
    /** @var array<string, true> orders to announce once their save has gone through */
    private static array $announce = [];

    public function saving(EntrySaving $event): void
    {
        $order = $event->entry;
        if ($order->collectionHandle() !== 'orders') {
            return;
        }

        $now = now()->format('Y-m-d H:i');
        $status = $order->get('status');

        if ($order->get('payment_status') === 'paid' && ! $order->get('paid_at')) {
            $order->set('paid_at', $now);
        }
        if (in_array($status, ['shipped', 'completed'], true) && ! $order->get('shipped_at')) {
            $order->set('shipped_at', $now);
        }
        if ($status === 'completed' && ! $order->get('delivered_at')) {
            $order->set('delivered_at', $now);
        }
        if ($status === 'shipped' && ! $order->get('shipped_notified_at')) {
            $order->set('shipped_notified_at', $now);
            self::$announce[$order->id()] = true;
        }
    }

    public function saved(EntrySaved $event): void
    {
        $order = $event->entry;
        if (! isset(self::$announce[$order->id()])) {
            return;
        }
        unset(self::$announce[$order->id()]);

        $site = $order->get('customer_site') ?: 'hm';
        $lang = Site::get($site)?->lang() ?? 'en';
        $pickup = $order->get('delivery') !== 'delivery';
        $courier = Shop::courier($order);
        $params = [
            'name' => $order->get('customer_name'),
            'number' => $order->get('title'),
            'courier' => $courier['name'] ?? '',
            'tracking' => $order->get('tracking_number'),
            'place' => Shop::setting($site, 'pickup_place'),
            'url' => Shop::orderUrl($site, $order),
        ];

        if ($email = $order->get('customer_email')) {
            try {
                Mail::to($email)->locale($lang)->send(new OrderShipped($order, $params, $pickup, $pickup ? null : $courier));
            } catch (Throwable $e) {
                Log::error("Shop: could not email the customer that order {$params['number']} was sent: {$e->getMessage()}");
            }
        }

        PlaceOrder::notifyTeam($order, "Order {$params['number']} marked as sent", Shop::whatsappUrl($order->get('customer_phone'), self::message($params, $pickup, $lang)));
    }

    /** The WhatsApp text for the customer, in their language. */
    private static function message(array $params, bool $pickup, string $lang): string
    {
        $lines = [trans($pickup ? 'site.shop.msg_ready' : 'site.shop.msg_sent', $params, $lang)];
        if (! $pickup && $params['tracking']) {
            $lines[] = trans('site.shop.msg_tracking', $params, $lang);
        }
        $lines[] = trans('site.shop.msg_follow', $params, $lang);

        return implode("\n", $lines);
    }
}
