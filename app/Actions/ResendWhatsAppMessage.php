<?php

namespace App\Actions;

use App\Shop\WhatsApp;
use Illuminate\Validation\ValidationException;
use Statamic\Actions\Action;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\User;

/**
 * "Resend" in the WhatsApp messages log (control panel → Collections → WhatsApp messages: a message's ⋯ menu, or
 * several at once in the list). Sends the same text again, as a new message in the log linked to the first
 * (App\Shop\WhatsApp::resend()); one message can go to another number instead, for a mistyped one. At most
 * WhatsApp::RESEND_AT_ONCE at a time. For whoever can edit orders.
 */
class ResendWhatsAppMessage extends Action
{
    public static function title()
    {
        return 'Resend';
    }

    public function visibleTo($item)
    {
        return $item instanceof Entry && $item->collectionHandle() === WhatsApp::COLLECTION;
    }

    public function visibleToBulk($items)
    {
        return $items->every(fn ($item) => $this->visibleTo($item));
    }

    public function authorize($user, $item)
    {
        return $user->can('edit orders entries');
    }

    public function buttonText()
    {
        /** @translation */
        return 'Resend|Resend :count messages';
    }

    public function confirmationText()
    {
        /** @translation */
        return 'Send this message again, the same text, as a new message in the log?|Send these :count messages again, the same text each, as new messages in the log?';
    }

    public function warningText()
    {
        /** @translation */
        return 'Buyers get it again on WhatsApp. Only resend what didn\'t arrive or what they asked for: too many messages can get the shop\'s number blocked.';
    }

    protected function fieldItems()
    {
        return [
            'to' => [
                'type' => 'text',
                'display' => 'Send to another number (optional)',
                'instructions' => 'For one message only, when the number was mistyped: with the country code for numbers abroad (+1 …); Lao numbers like 020 5555 1234 are fine. Empty: the same number.',
                'input_type' => 'tel',
            ],
        ];
    }

    public function run($messages, $values)
    {
        $to = trim((string) ($values['to'] ?? ''));
        if ($messages->count() > WhatsApp::RESEND_AT_ONCE) {
            throw ValidationException::withMessages(['to' => 'Resend at most '.WhatsApp::RESEND_AT_ONCE.' messages at a time.']);
        }
        if ($to !== '' && $messages->count() > 1) {
            throw ValidationException::withMessages(['to' => 'Another number works for one message at a time.']);
        }
        if ($to !== '' && ! WhatsApp::number($to)) {
            throw ValidationException::withMessages(['to' => 'That doesn\'t look like a phone number.']);
        }

        $by = User::current()?->name();
        $problems = $messages->map(fn (Entry $message) => WhatsApp::resend($message, $by, $to ?: null))->filter();
        $queued = $messages->count() - $problems->count();

        return trim(($queued ? trans_choice('Sent again: :count message queued.|Sent again: :count messages queued.', $queued) : '')
            .($problems->isNotEmpty() ? ' Not sent: '.$problems->unique()->implode('; ') : ''));
    }
}
