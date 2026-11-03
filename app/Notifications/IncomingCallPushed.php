<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/** "Alice is calling you" on the member's phone; tapping it opens the call (App\Support\Calls). Useless after the ringing. */
class IncomingCallPushed extends Notification
{
    public function __construct(
        public int $callId,
        public string $title,
        public string $body,
        public string $url,
    ) {}

    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->title)
            ->body($this->body)
            ->icon(url('/icons/icon-192.png'))
            ->badge(url('/icons/badge-96.png'))
            ->tag('call-'.$this->callId)
            ->vibrate([400, 200, 400, 200, 400])
            ->data(['url' => $this->url])
            // Only while it rings.
            ->options(['TTL' => 45, 'urgency' => 'high']);
    }
}
