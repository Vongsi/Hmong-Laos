<?php

namespace App\Shop;

use App\Mail\OrderNotice;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;
use Throwable;

/**
 * Turns the cart into an order entry (collection "orders", managed in the control panel),
 * takes the items out of stock and tells the team.
 */
class PlaceOrder
{
    /**
     * @param  array{name: string, phone: string, email: ?string, delivery: string, address: ?string, payment: string, note: ?string, user: int|string|null}  $customer
     *
     * @throws ShopException when the cart is empty or something sold out in the meantime
     */
    public function __invoke(Cart $cart, array $customer, string $site): EntryContract
    {
        // One order at a time, so two buyers can't both take the last item.
        $order = Cache::lock('shop:place-order', 15)->block(10, function () use ($cart, $customer, $site) {
            $lines = $cart->lines($site);

            if ($lines->isEmpty()) {
                throw new ShopException(__('site.shop.cart_empty'));
            }

            // Stock is per product, so add up its lines (different sizes or colours) before checking.
            $perProduct = $lines->groupBy(fn ($line) => $line->product->id());
            foreach ($perProduct as $productLines) {
                $first = $productLines->first();
                $short = $productLines->contains(fn ($line) => $line->quantity < 1 || $line->quantity < $line->requested);
                if ($short || ($first->available !== null && $productLines->sum('requested') > $first->available)) {
                    throw new ShopException(__('site.shop.not_enough', ['product' => $first->title]));
                }
            }

            $subtotal = $lines->sum('lineTotal');
            $deliveryFee = $customer['delivery'] === 'delivery' ? (int) Shop::setting($site, 'delivery_fee') : 0;

            $order = Entry::make()
                ->collection('orders')
                ->locale('hm')
                ->date(now())
                ->published(true)
                ->data([
                    'title' => $this->newNumber(),
                    'token' => Str::random(32),
                    'items' => $lines->map(fn ($line) => [
                        'product' => $line->title,
                        'size' => $line->size,
                        'colour' => $line->colour,
                        'quantity' => $line->quantity,
                        'unit_price' => $line->unitPrice,
                        'line_total' => $line->lineTotal,
                        'product_id' => $line->product->id(),
                    ])->all(),
                    'subtotal' => $subtotal,
                    'delivery_fee' => $deliveryFee,
                    'total' => $subtotal + $deliveryFee,
                    'customer_name' => $customer['name'],
                    'customer_phone' => $customer['phone'],
                    'customer_email' => $customer['email'] ?: null,
                    'delivery' => $customer['delivery'],
                    'address' => $customer['delivery'] === 'delivery' ? $customer['address'] : null,
                    'note' => $customer['note'] ?: null,
                    'customer' => $customer['user'] ? [$customer['user']] : null,
                    'customer_site' => $site,
                    'status' => 'new',
                    'payment_status' => 'pending',
                    'payment_method' => $customer['payment'],
                    // What the courier collects for us (Anousith's COD) when a cash order is delivered.
                    'cod_amount' => $customer['payment'] === 'cash' && $customer['delivery'] === 'delivery' ? $subtotal + $deliveryFee : null,
                ]);
            $order->save();

            // Once per product, for all its lines. saveQuietly: a stock change mustn't look like an edit
            // (no Facebook post, no notification).
            foreach ($perProduct as $productLines) {
                $first = $productLines->first();
                if ($first->available !== null) {
                    $first->product->set('stock', $first->available - $productLines->sum('quantity'))->saveQuietly();
                }
            }

            $cart->clear();

            return $order;
        });

        self::notifyTeam($order, 'New order '.$order->get('title'));

        return $order;
    }

    /** Emails the team, with an optional ready-made WhatsApp message for the customer. A mail problem must never lose the order. */
    public static function notifyTeam(EntryContract $order, string $subject, ?string $whatsappUrl = null): void
    {
        $to = Shop::setting('hm', 'notify_email');

        if (! $to) {
            Log::warning("Shop: no 'Send new orders to' address in Shop settings; nobody was emailed about order {$order->get('title')}.");

            return;
        }

        try {
            Mail::to($to)->send(new OrderNotice($order, $subject, $whatsappUrl));
        } catch (Throwable $e) {
            Log::error("Shop: could not email the team about order {$order->get('title')}: {$e->getMessage()}");
        }
    }

    private function newNumber(): string
    {
        do {
            // DAH = Daim Av Hlub. Easy to read out on the phone; no 0/O or 1/I mix-ups.
            $number = 'DAH-'.now()->format('ymd').'-'.substr(str_shuffle(str_repeat('ABCDEFGHJKLMNPQRSTUVWXYZ23456789', 2)), 0, 4);
        } while (Entry::query()->where('collection', 'orders')->where('title', $number)->exists());

        return $number;
    }
}
