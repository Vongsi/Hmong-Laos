<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * A call (App\Support\Calls) ringing, answered or ended, for one of its two members on their own channel, whatever
 * page they have open (public/js/calls.js).
 */
class CallSignal implements ShouldBroadcastNow
{
    public int $conversationId;

    /** @param  array  $call  App\Support\Calls::describe() as this member sees it */
    public function __construct(public int $recipientId, public string $action, public array $call)
    {
        $this->conversationId = (int) ($call['conversation'] ?? 0);
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('App.Models.User.'.$this->recipientId);
    }

    public function broadcastAs(): string
    {
        return 'call';
    }

    public function broadcastWith(): array
    {
        return ['action' => $this->action] + $this->call;
    }
}
