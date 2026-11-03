<?php

namespace App\Tags;

use App\Support\Messaging;
use Illuminate\Support\Facades\Auth;
use Statamic\Facades\Blink;
use Statamic\Tags\Tags;

/**
 * {{ messages_badge }}: the signed-in member's unread messages as a small red number (hidden at 0), on My account in
 * the header, the phone tab bar and the menu. public/js/live.js keeps every [data-unread-messages] up to date.
 */
class MessagesBadge extends Tags
{
    protected static $handle = 'messages_badge';

    public function index(): string
    {
        $userId = Auth::id();
        if (! $userId) {
            return '';
        }
        $count = Blink::once('messages-unread-'.$userId, fn () => Messaging::unreadCount((int) $userId));

        return '<b class="msg-badge" data-unread-messages aria-hidden="true"'.($count ? '' : ' hidden').'>'.($count > 99 ? '99+' : $count).'</b>';
    }
}
