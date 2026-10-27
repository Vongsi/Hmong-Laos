<?php

namespace App\Shop;

use App\Appearance\Background;
use Illuminate\Support\Carbon;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;

/**
 * The Payments list (control panel → Collections → Payments, read only): every payment we hear about, from
 * any bank, kept for reference and for checking against the bank's statements. Each has a bank, a payment
 * type and a result; the options are in resources/blueprints/collections/payments/payment.yaml.
 *
 * - BCEL One QR payments: App\Shop\OnePay.
 * - Bank transfers, from the receipt the customer uploads: App\Shop\Receipts.
 */
class Payments
{
    /**
     * Adds a payment to the list. $fields are the blueprint's fields; empty ones are left out.
     *
     * @param  array<string, mixed>  $fields
     */
    public static function record(array $fields, ?EntryContract $order = null, ?Carbon $time = null): EntryContract
    {
        $payment = Entry::make()->collection('payments')->locale('hm')->published(true)
            ->date(($time ?? now())->copy()->utc())
            ->data(array_filter(
                $fields + ['for_order' => $order ? [$order->id()] : null],
                fn ($value) => $value !== null && $value !== '' && $value !== [],
            ));
        $payment->saveQuietly();

        return $payment;
    }

    /** Whether a payment with this key was recorded: the same message or picture again. */
    public static function exists(string $key): bool
    {
        return Entry::query()->where('collection', 'payments')->where('payment_key', $key)->exists();
    }

    /** A payment with this key recorded for a different order than $order, if any. */
    public static function keyUsedElsewhere(string $key, EntryContract $order): ?EntryContract
    {
        return self::elsewhere(Entry::query()->where('collection', 'payments')->where('payment_key', $key)->get(), $order);
    }

    /** Whether a payment was recorded as paying this order (from any bank). */
    public static function paidFor(EntryContract $order): bool
    {
        return Entry::query()->where('collection', 'payments')->where('result', 'paid')
            ->whereJsonContains('for_order', $order->id())->exists();
    }

    /** Whether the bank told us of a payment for this order (not only the team marking it paid by hand). */
    public static function bankPaidFor(EntryContract $order): bool
    {
        return Entry::query()->where('collection', 'payments')->where('result', 'paid')
            ->whereJsonContains('for_order', $order->id())->get()
            ->contains(fn (EntryContract $payment) => ! str_starts_with((string) $payment->get('payment_key'), 'manual:'));
    }

    /**
     * A payment for a different order with one of these bank references, if any: the same receipt sent for
     * two orders, or a BCEL One payment's receipt sent as a transfer.
     *
     * @param  list<string>  $references
     */
    public static function referenceUsedElsewhere(array $references, EntryContract $order): ?EntryContract
    {
        foreach ($references as $reference) {
            foreach (['reference_1', 'reference_2'] as $field) {
                $found = self::elsewhere(Entry::query()->where('collection', 'payments')->where($field, $reference)->get(), $order);
                if ($found) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * When the team sets an order Paid: the payments it was checked with (receipts, a BCEL One payment that didn't
     * match) count as paid; and if nothing in the list covers it (cash, or a transfer confirmed without a
     * receipt), a line is added, saying who marked it paid. So every paid order has its line in Payments.
     */
    public static function confirm(EntryContract $order, ?string $by): void
    {
        Entry::query()->where('collection', 'payments')
            ->whereIn('result', ['looks_right', 'check', 'unread'])
            ->whereJsonContains('for_order', $order->id())->get()
            ->each(fn (EntryContract $payment) => $payment->set('result', 'paid')->saveQuietly());

        if (! self::paidFor($order) && ! self::exists('manual:'.$order->id())) {
            self::recordManual($order, $by);
        }
    }

    /** The line for an order the team marked paid by hand: cash at pick-up or from the courier, or a confirmed transfer. */
    public static function recordManual(EntryContract $order, ?string $by): EntryContract
    {
        $method = (string) $order->get('payment_method');
        $type = ['cash' => 'cash', 'onepay' => 'qr'][$method] ?? 'transfer';
        $courier = $method === 'cash' && $order->get('delivery') === 'delivery' ? (Shop::courier($order)['name'] ?? 'the courier') : null;
        $paidAt = $order->get('paid_at') ? Carbon::parse($order->get('paid_at'), 'UTC') : now();

        return self::record([
            'title' => implode(' · ', [Shop::kip((int) $order->get('total')), $order->get('title'), $type === 'cash' ? 'cash' : 'confirmed']),
            'result' => 'paid',
            'bank' => $method === 'onepay' ? 'bcel' : null,
            'payment_type' => $type,
            'amount' => (int) $order->get('total'),
            'currency' => 'LAK',
            'description' => match (true) {
                $courier !== null => 'Collected by the courier (COD)',
                $type === 'cash' => 'Paid at pick-up',
                $type === 'qr' => 'Confirmed by the team (no message from BCEL)',
                default => 'Confirmed by the team'.($order->get('receipt') ? '' : ' (no receipt)'),
            },
            'received_by' => $by,
            // Who has the cash until it is handed in (Cash not handed in yet, on the dashboard).
            'cash_with' => $type === 'cash' ? ($courier ?? $by) : null,
            'handed_in' => $type === 'cash' ? false : null,
            'payment_key' => 'manual:'.$order->id(),
        ], $order, $paidAt);
    }

    /** A BCEL One payment arrived for an order the team had already marked paid: its line replaces the team's. */
    public static function dropManual(EntryContract $order): void
    {
        Entry::query()->where('collection', 'payments')->where('payment_key', 'manual:'.$order->id())->get()->each->deleteQuietly();
    }

    /** When the team sets an order Refunded, its payments say so (totals stay right). */
    public static function refund(EntryContract $order): void
    {
        Entry::query()->where('collection', 'payments')
            ->whereIn('result', ['paid', 'looks_right', 'check', 'unread'])
            ->whereJsonContains('for_order', $order->id())->get()
            ->each(fn (EntryContract $payment) => $payment->set('result', 'refunded')->saveQuietly());
    }

    /** When the team doesn't accept an order's payment, the receipts it was checked with say so, with their message. */
    public static function rejectReceipts(EntryContract $order, ?string $message): void
    {
        Entry::query()->where('collection', 'payments')->where('payment_type', 'transfer')
            ->whereIn('result', ['looks_right', 'check', 'unread'])
            ->whereJsonContains('for_order', $order->id())->get()
            ->each(fn (EntryContract $payment) => $payment->set('result', 'rejected')->set('team_note', $message)->saveQuietly());
    }

    /**
     * Adds a step to the order's payment history (doesn't save). Kept on the order outside its blueprint, so a
     * control panel save never drops a step the customer added meanwhile; shown on the order page and, read only,
     * in the control panel (payment_log, receipts).
     *
     * @param  'reported'|'rejected'|'paid'|'refunded'  $event  reported: by the customer (note, receipt); rejected: by the team (note)
     */
    public static function addHistory(EntryContract $order, string $event, array $details = []): void
    {
        $history = self::history($order);
        $history[] = array_filter(['at' => now()->utc()->format('Y-m-d H:i'), 'event' => $event] + $details, fn ($value) => $value !== null && $value !== '');
        $order->set('payment_history', $history);
    }

    /**
     * The order's payment history, oldest first.
     *
     * @return list<array{at: string, event: string, note?: string, receipt?: string, check?: string}>
     */
    public static function history(EntryContract $order): array
    {
        return array_values(array_filter((array) $order->get('payment_history'), 'is_array'));
    }

    /** Notes the automatic check on the history step with that receipt (doesn't save). */
    public static function noteCheck(EntryContract $order, string $receiptPath, string $result): void
    {
        $order->set('payment_history', array_map(
            fn (array $step) => ($step['receipt'] ?? null) === $receiptPath ? ['check' => $result] + $step : $step,
            self::history($order),
        ));
    }

    /**
     * Every receipt the customer added, newest first (paths in the receipts container).
     *
     * @return list<string>
     */
    public static function receipts(EntryContract $order): array
    {
        return array_values(array_reverse(array_filter(array_column(self::history($order), 'receipt'))));
    }

    /** The history as text, for the team (control panel, read only). */
    public static function historyText(EntryContract $order): ?string
    {
        $checks = ['looks_right' => 'receipt looks right', 'check' => 'check the receipt', 'unread' => 'receipt not read automatically'];
        $lines = array_map(function (array $step) use ($checks) {
            $time = Carbon::parse($step['at'], 'UTC')->setTimezone(Background::TIMEZONE)->format('d/m H:i');

            return $time.' · '.implode(' · ', array_filter(match ($step['event']) {
                'reported' => ['Customer reported a payment', isset($step['note']) ? '"'.$step['note'].'"' : null,
                    isset($step['receipt']) ? 'receipt '.basename($step['receipt']) : null, $checks[$step['check'] ?? ''] ?? null],
                'rejected' => ['Team: not accepted', isset($step['note']) ? '"'.$step['note'].'"' : 'no message'],
                'paid' => ['Paid'],
                'refunded' => ['Refunded'],
                default => [$step['event']],
            }));
        }, self::history($order));

        return $lines ? implode("\n", $lines) : null;
    }

    /** The order a payment was for, if it has one. */
    public static function order(EntryContract $payment): ?EntryContract
    {
        $id = ((array) $payment->get('for_order'))[0] ?? null;

        return $id ? Entry::find($id) : null;
    }

    private static function elsewhere($payments, EntryContract $order): ?EntryContract
    {
        return $payments->first(fn (EntryContract $payment) => ! in_array($order->id(), (array) $payment->get('for_order'), true));
    }
}
