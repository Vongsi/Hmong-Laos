<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class NewEntryPushed extends Notification
{
    public function __construct(
        public string $entryId,
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
            ->tag('entry-'.$this->entryId)
            ->data(['url' => $this->url])
            // Keep trying to deliver for a day if the phone is offline.
            ->options(['TTL' => 86400, 'urgency' => 'normal']);
    }
}
