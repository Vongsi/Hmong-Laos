<?php

namespace App\Listeners;

use App\Mail\PayoutSent;
use App\Mail\TeamNotice;
use App\Models\User;
use App\Shop\SellerPayouts;
use App\Shop\Shop;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Events\EntrySaved;
use Statamic\Events\EntrySaving;
use Statamic\Facades\Site;
use Statamic\Facades\User as TeamMember;
use Throwable;

/**
 * Seller payouts (App\Shop\SellerPayouts) as the team works on them, with a maker and a checker:
 * - To pay → Sent (maker): needs the transfer screenshot, the date and the reference (unless paid in cash); the
 *   maker is noted and the team is emailed that a payout is waiting to be checked.
 * - Sent → Paid (checker): must be another team member than the maker (unless Shop settings → "Payouts need a
 *   second person" is off); the checker is noted and the member gets an email in their language, once.
 * - Sent → To pay: sent back to the maker (the checker's note says what to fix).
 * - A payout that was checked and paid can't be changed any more.
 * Problems stop the save and show on the field in the control panel.
 */
class TrackSellerPayout
{
    /** @var array<int, true> payouts whose member is told once the save has gone through */
    private static array $announce = [];

    /** @var array<int, true> payouts the team is told are waiting to be checked */
    private static array $toCheck = [];

    public function saving(EntrySaving $event): void
    {
        $payout = $event->entry;
        if ($payout->collectionHandle() !== SellerPayouts::COLLECTION) {
            return;
        }

        // Made with "Create Entry" instead of the dashboard: no seller, amount or items to pay.
        if (! $payout->get('lines')) {
            throw ValidationException::withMessages(['payout_status' => 'Payouts are prepared on the Dashboard: Seller payouts → Prepare payout. That fills in the seller, the amount and the items.']);
        }

        $status = $payout->get('payout_status') ?: 'to_pay';
        $member = TeamMember::current();
        $memberId = $member ? (string) $member->id() : null;

        if ($payout->get('checked_by_id') && $status !== 'paid') {
            throw ValidationException::withMessages(['payout_status' => 'This payout was checked and paid, so it can no longer be changed.']);
        }

        if ($status !== 'to_pay' && ! $payout->get('checked_by_id')) {
            $missing = array_filter([
                'transfer_proof' => $payout->get('transfer_proof') ? null : 'Attach the transfer screenshot.',
                'paid_on' => $payout->get('paid_on') ? null : 'Fill in the date it was paid.',
                'reference' => filled($payout->get('reference')) || $payout->get('method') === 'cash' ? null : 'Fill in the bank reference.',
            ]);
            if ($missing) {
                throw ValidationException::withMessages($missing);
            }
        }

        $needsChecker = Shop::setting('hm', 'payout_needs_checker') !== false;
        if ($status === 'sent' && ! $payout->get('made_by_id')) {
            $this->mark($payout, 'made', $member);
            self::$toCheck[spl_object_id($payout)] = true;
        } elseif ($status === 'to_pay' && $payout->get('made_by_id')) {
            // Sent back by the checker (or undone by the maker): to be sent again.
            $payout->set('made_by_id', null)->set('made_by', null)->set('made_at', null);
        } elseif ($status === 'paid' && ! $payout->get('checked_by_id')) {
            if (! $payout->get('made_by_id')) {
                if ($needsChecker) {
                    throw ValidationException::withMessages(['payout_status' => 'First set it to "Sent" with the screenshot; then another team member checks it and sets "Paid".']);
                }
                $this->mark($payout, 'made', $member);
            }
            if ($needsChecker && ($memberId === null || $memberId === (string) $payout->get('made_by_id'))) {
                throw ValidationException::withMessages(['payout_status' => 'You sent this payout, so another team member has to check it and set "Paid".']);
            }
            $this->mark($payout, 'checked', $member);
            $payout->set('notified_at', now()->format('Y-m-d H:i'));
            self::$announce[spl_object_id($payout)] = true;
        }

        $seller = (string) (((array) $payout->get('seller'))[0] ?? '');
        // Payouts from before numbering get the next number the first time they are saved.
        if (! $payout->get('payout_number')) {
            $payout->set('payout_number', SellerPayouts::nextNumber());
        }
        $payout->set('title', SellerPayouts::title((string) $payout->get('payout_number'), (int) $payout->get('amount'), $seller, $status));
    }

    public function saved(EntrySaved $event): void
    {
        $payout = $event->entry;
        $key = spl_object_id($payout);
        if (isset(self::$toCheck[$key])) {
            unset(self::$toCheck[$key]);
            $this->askForCheck($payout);
        }
        if (isset(self::$announce[$key])) {
            unset(self::$announce[$key]);
            $this->tellMember($payout);
        }
    }

    /** Notes who made or checked it, and when. */
    private function mark(EntryContract $payout, string $step, $member): void
    {
        $payout->set($step.'_by_id', $member ? (string) $member->id() : null)
            ->set($step.'_by', $member?->name() ?? 'unknown')
            ->set($step.'_at', now()->format('Y-m-d H:i'));
    }

    /** The team inbox: a payout is waiting for a second person. */
    private function askForCheck(EntryContract $payout): void
    {
        $to = Shop::setting('hm', 'notify_email');
        if (! $to) {
            return;
        }
        try {
            Mail::to($to)->send(new TeamNotice(
                'Payout to check: '.$payout->get('title'),
                array_filter([
                    'Sent by '.$payout->get('made_by').', reference '.($payout->get('reference') ?: '(cash)').'.',
                    'Another team member: open it, check the screenshot against the bank, then set "Paid" (or "To pay" with a note).',
                ]),
                $payout->editUrl(),
                'Open the payout',
            ));
        } catch (Throwable $e) {
            Log::error("Shop: could not email the team about payout {$payout->id()}: {$e->getMessage()}");
        }
    }

    /** The member: their money was sent, once it has been checked. */
    private function tellMember(EntryContract $payout): void
    {
        $user = User::find(((array) $payout->get('seller'))[0] ?? null);
        if (! $user?->email) {
            return;
        }

        $site = Site::get((string) $user->preferred_lang) ?? Site::default();
        $details = [
            'name' => $user->name,
            'number' => (string) $payout->get('payout_number'),
            'amount' => Shop::kip((int) $payout->get('amount')),
            'date' => SellerPayouts::day($payout->get('paid_on')),
            'ref' => (string) $payout->get('reference'),
            'note' => (string) $payout->get('note'),
            'breakdown' => (string) $payout->get('breakdown'),
            'url' => Shop::url($site->handle(), 'account/payouts'),
        ];

        try {
            Mail::to($user->email)->locale($site->lang())->send(new PayoutSent($details));
        } catch (Throwable $e) {
            Log::error("Shop: could not email {$user->email} about their payout: {$e->getMessage()}");
        }
    }
}
