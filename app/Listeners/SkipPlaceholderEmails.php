<?php

namespace App\Listeners;

use App\Auth\SocialSignIn;
use Illuminate\Mail\Events\MessageSending;
use Symfony\Component\Mime\Address;

/**
 * Members who joined with Facebook without an email have a placeholder that can never receive mail
 * (App\Auth\SocialSignIn). Whatever would be emailed to them (a payout, a review of their post) is sent to the other
 * recipients only, or not at all; they still see it on the site.
 */
class SkipPlaceholderEmails
{
    public function handle(MessageSending $event): ?bool
    {
        $message = $event->message;
        $keep = fn (array $addresses) => array_values(array_filter($addresses, fn (Address $address) => ! SocialSignIn::isPlaceholder($address->getAddress())));

        $to = $keep($message->getTo());
        if (! $to) {
            return false;
        }
        if (count($to) !== count($message->getTo())) {
            $message->to(...$to);
        }
        foreach (['Cc', 'Bcc'] as $field) {
            $all = $message->{'get'.$field}();
            $kept = $keep($all);
            if (count($kept) !== count($all)) {
                $message->getHeaders()->remove($field);
                if ($kept) {
                    $message->{strtolower($field)}(...$kept);
                }
            }
        }

        return null;
    }
}
