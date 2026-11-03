<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/** "New message from …" on the member's phone, without the message itself (lock screens are seen by others). */
class NewMessagePushed extends Notification
{
    public function __construct(
        public int $conversationId,
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
            // One notification per conversation, replaced by the next.
            ->tag('messages-'.$this->conversationId)
            ->data(['url' => $this->url])
            ->options(['TTL' => 86400, 'urgency' => 'high']);
    }
}
