<?php

namespace App\Shop;

use App\Appearance\Background;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;

/**
 * Members who sell through "Sell with us" are paid by the team: customers pay the shop, and once an order is
 * delivered (or picked up) and paid, each member's items in it are owed to them, less the shop fee in Shop
 * settings. The team sees who is owed what on the dashboard (App\Widgets\SellerPayouts), prepares a payout for a
 * seller (an entry in Collections → Seller payouts with the items it covers); a team member transfers the money,
 * attaches the screenshot and sets it Sent (maker), another checks it and sets it Paid (checker); then the seller
 * is emailed (App\Listeners\TrackSellerPayout) and sees it on My account → My earnings. An item is in at most one payout; deleting a payout that isn't paid yet puts
 * its items back to "owed".
 */
class SellerPayouts
{
    const COLLECTION = 'seller_payouts';

    /** The shop fee on members' products, in percent (Shop settings; 0 by default). */
    public static function fee(): int
    {
        return max(0, min(100, (int) Shop::setting('hm', 'seller_fee')));
    }

    /** The member who sent an order item's product: kept on the item since this was added, else from the product. */
    public static function sellerOf(array $item): ?string
    {
        if (filled($item['seller'] ?? null)) {
            return (string) $item['seller'];
        }
        $product = filled($item['product_id'] ?? null) ? Entry::find($item['product_id'])?->root() : null;
        $seller = $product ? (((array) $product->get('submitted_by'))[0] ?? null) : null;

        return filled($seller) ? (string) $seller : null;
    }

    /**
     * What sellers are owed: their items in delivered, paid orders that are in no payout yet, with the fee taken
     * off. Keyed by the seller's user id.
     *
     * @return Collection<string, array{seller: string, lines: list<array>, total: int}>
     */
    public static function owed(?string $sellerId = null): Collection
    {
        $covered = self::covered();
        $fee = self::fee();
        $lines = [];

        // Order status is a field called "status", so it is checked here rather than in the query.
        $orders = Entry::query()->where('collection', 'orders')->where('payment_status', 'paid')->get()
            ->filter(fn (EntryContract $order) => $order->get('status') === 'completed');
        foreach ($orders as $order) {
            foreach ((array) $order->get('items') as $index => $item) {
                $seller = self::sellerOf((array) $item);
                if (! $seller || ($sellerId !== null && $seller !== $sellerId) || isset($covered[$order->id().':'.$index])) {
                    continue;
                }
                $gross = (int) ($item['line_total'] ?? 0);
                $cut = (int) round($gross * $fee / 100);
                $lines[$seller][] = [
                    'order' => $order->id(),
                    'order_number' => (string) $order->get('title'),
                    'index' => $index,
                    'product' => (string) ($item['product'] ?? ''),
                    'quantity' => (int) ($item['quantity'] ?? 1),
                    'gross' => $gross,
                    'fee' => $cut,
                    'amount' => $gross - $cut,
                ];
            }
        }

        return collect($lines)->map(fn (array $sellerLines, $seller) => [
            'seller' => (string) $seller,
            'lines' => $sellerLines,
            'total' => array_sum(array_column($sellerLines, 'amount')),
        ]);
    }

    /** A payout for everything the seller is owed now, to be paid ("To pay"); null if nothing is owed. */
    public static function prepare(string $sellerId): ?EntryContract
    {
        // One at a time, so two team members can't prepare the same items or take the same number.
        return Cache::lock('shop:seller-payouts', 15)->block(10, function () use ($sellerId) {
            $owed = self::owed($sellerId)->get($sellerId);
            if (! $owed || ! $owed['lines']) {
                return null;
            }

            $number = self::nextNumber();
            $payout = Entry::make()->collection(self::COLLECTION)->locale('hm')->published(true)->date(now())->data([
                'title' => self::title($number, $owed['total'], $sellerId, 'to_pay'),
                'payout_number' => $number,
                'payout_status' => 'to_pay',
                'seller' => [$sellerId],
                'amount' => $owed['total'],
                'fee_percent' => self::fee(),
                'breakdown' => self::breakdown($owed['lines']),
                'method' => 'transfer',
                // Which order items it covers ("order id:item index"); not a blueprint field, so control panel saves keep it.
                'lines' => $owed['lines'],
            ]);
            $payout->save();

            return $payout;
        });
    }

    /** The next payout number: "PO-2026-0001", counting up within each year (Laos time). */
    public static function nextNumber(): string
    {
        $prefix = 'PO-'.now()->setTimezone(Background::TIMEZONE)->format('Y').'-';
        $last = Entry::query()->where('collection', self::COLLECTION)->get()
            ->map(fn (EntryContract $payout) => (string) $payout->get('payout_number'))
            ->filter(fn (string $number) => str_starts_with($number, $prefix))
            ->map(fn (string $number) => (int) substr($number, strlen($prefix)))
            ->max() ?? 0;

        return $prefix.str_pad((string) ($last + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * The seller's payouts, newest first.
     *
     * @return Collection<int, EntryContract>
     */
    public static function payouts(string $sellerId): Collection
    {
        return Entry::query()->where('collection', self::COLLECTION)->whereJsonContains('seller', $sellerId)
            ->orderBy('date', 'desc')->get()->values();
    }

    /** "PO-2026-0001 · 450,000 ₭ · Somchai · to pay" (or "sent, to check", or "paid") */
    public static function title(?string $number, int $amount, string $sellerId, string $status): string
    {
        return ($number ? $number.' · ' : '').Shop::kip($amount).' · '.(User::find($sellerId)?->name ?? 'member '.$sellerId).' · '
            .(['paid' => 'paid', 'sent' => 'sent, to check'][$status] ?? 'to pay');
    }

    /** Where to send the seller's money (My account), for the team; null if they haven't given it. */
    public static function payTo(?User $user): ?string
    {
        if (! $user || ! filled($user->payout_account_number)) {
            return null;
        }

        return implode(' · ', array_filter([$user->payout_bank, $user->payout_account_name, $user->payout_account_number]));
    }

    /** The items a payout covers, one per line, for the team and the seller's email. */
    public static function breakdown(array $lines): string
    {
        return implode("\n", array_map(fn (array $line) => $line['order_number'].' · '.$line['product'].' × '.$line['quantity'].' · '
            .Shop::kip($line['gross']).($line['fee'] ? ' − fee '.Shop::kip($line['fee']).' = '.Shop::kip($line['amount']) : ''), $lines));
    }

    /** A date in Laos time, for people. */
    public static function day($date): string
    {
        return $date ? Carbon::parse($date, 'UTC')->setTimezone(Background::TIMEZONE)->translatedFormat('j M Y') : '';
    }

    /** "orderId:index" of every item already in a payout (to pay or paid). */
    private static function covered(): array
    {
        $covered = [];
        foreach (Entry::query()->where('collection', self::COLLECTION)->get() as $payout) {
            foreach ((array) $payout->get('lines') as $line) {
                $covered[($line['order'] ?? '').':'.($line['index'] ?? '')] = true;
            }
        }

        return $covered;
    }
}
