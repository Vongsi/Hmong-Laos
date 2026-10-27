<?php

namespace App\Listeners;

use App\Appearance\Background;
use Statamic\Events\EntrySaving;
use Statamic\Facades\User;

/**
 * Cash lines in Payments (cash at pick-up, or collected by the courier): when the team ticks "Cash handed in",
 * the date (if left empty) and who ticked it are filled in; unticking clears them. The dashboard's "Cash not
 * handed in yet" (App\Widgets\CashOnHand) lists the rest.
 */
class TrackCashHandIn
{
    public function saving(EntrySaving $event): void
    {
        $payment = $event->entry;
        if ($payment->collectionHandle() !== 'payments' || $payment->get('payment_type') !== 'cash') {
            return;
        }

        if ($payment->get('handed_in')) {
            if (! $payment->get('handed_in_on')) {
                $payment->set('handed_in_on', now()->setTimezone(Background::TIMEZONE)->format('Y-m-d'));
            }
            if (! $payment->get('handed_in_by')) {
                $payment->set('handed_in_by', User::current()?->name());
            }
        } else {
            $payment->set('handed_in_on', null)->set('handed_in_by', null);
        }
    }
}
