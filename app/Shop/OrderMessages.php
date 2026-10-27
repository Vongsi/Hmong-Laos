<?php

namespace App\Shop;

use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Site;

/**
 * What the shop tells a buyer on WhatsApp about their order, in their language: a greeting, a line for each update,
 * the link to their order page and the shop's name. Sent by itself from the shop's number (App\Shop\WhatsApp), or
 * by the team with the ready-made link in their email (Shop::whatsappUrl()).
 */
class OrderMessages
{
    /** The updates a buyer can get, in the order they happen, so a save that makes several reads in order. */
    const EVENTS = ['received', 'confirmed', 'paid', 'rejected', 'shipped', 'completed', 'cancelled'];

    /** For the team (control panel, Shop settings). */
    const LABELS = [
        'received' => 'Order received',
        'confirmed' => 'Order confirmed',
        'paid' => 'Payment received',
        'rejected' => 'Payment not accepted',
        'shipped' => 'Sent / ready to pick up',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ];

    /** @param  list<string>  $events */
    public static function text(EntryContract $order, array $events): string
    {
        $site = $order->get('customer_site') ?: 'hm';
        $lang = Site::get($site)?->lang() ?? 'en';
        $courier = Shop::courier($order);
        $params = [
            'name' => (string) $order->get('customer_name'),
            'number' => (string) $order->get('title'),
            'total' => Shop::kip((int) $order->get('total')),
            'courier' => $courier['name'] ?? '',
            'tracking' => (string) $order->get('tracking_number'),
            'place' => (string) Shop::setting($site, 'pickup_place'),
            'message' => trim((string) $order->get('payment_reply')),
            'brand' => trans('site.brand', [], $lang),
            'url' => Shop::orderUrl($site, $order),
        ];
        $line = fn (string $key) => trans('site.shop.'.$key, $params, $lang);
        $pickup = $order->get('delivery') !== 'delivery';
        $paid = $order->get('payment_status') === 'paid';

        $lines = [];
        foreach (array_intersect(self::EVENTS, $events) as $event) {
            array_push($lines, ...match ($event) {
                'received' => [
                    $line('wa_received'),
                    match (true) {
                        $order->get('payment_method') === 'cash' => $line('wa_cash'),
                        ! $paid => $line('wa_pay'),
                        default => null,
                    },
                ],
                'confirmed' => [$line('wa_confirmed')],
                'paid' => [$line('wa_paid')],
                'rejected' => [
                    $line('wa_rejected'),
                    $params['message'] !== '' ? $line('msg_rejected_reason') : null,
                    $line('msg_rejected_again'),
                ],
                'shipped' => $pickup
                    ? [$line($params['place'] !== '' ? 'wa_ready' : 'wa_ready_plain')]
                    : [$line($params['courier'] !== '' ? 'wa_sent' : 'wa_sent_plain'), $params['tracking'] !== '' ? $line('msg_tracking') : null],
                'completed' => [$line('wa_completed')],
                'cancelled' => [$line('wa_cancelled'), $paid ? $line('wa_cancelled_paid') : null],
                default => [],
            });
        }
        $lines = array_values(array_filter($lines));
        // The order page, once: some updates already point there.
        if (! array_filter($lines, fn (string $text) => str_contains($text, $params['url']))) {
            $lines[] = $line('msg_follow');
        }

        return $line('wa_hello')."\n".implode("\n", $lines)."\n\n".$line('wa_signature');
    }
}
