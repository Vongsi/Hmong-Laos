<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Something changed in a conversation a member is in, other than a new message: read ("Seen"), a message edited or
 * deleted, members or the name changed. Their open Messages page redraws (public/js/live.js).
 */
class ConversationUpdated implements ShouldBroadcastNow
{
    public function __construct(public int $recipientId, public int $conversationId) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('App.Models.User.'.$this->recipientId);
    }

    public function broadcastAs(): string
    {
        return 'conversation.updated';
    }

    public function broadcastWith(): array
    {
        return ['conversation' => $this->conversationId];
    }
}
