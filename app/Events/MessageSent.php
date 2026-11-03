<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * A new private message, for the member it's for (their own channel, routes/channels.php): public/js/live.js
 * updates their unread badge and the open Messages page. Only ids: the page loads the message itself.
 */
class MessageSent implements ShouldBroadcastNow
{
    public function __construct(public int $recipientId, public int $conversationId) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('App.Models.User.'.$this->recipientId);
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    public function broadcastWith(): array
    {
        return ['conversation' => $this->conversationId];
    }
}
