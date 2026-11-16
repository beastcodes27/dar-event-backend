<?php

namespace App\Notifications\Channels;

use App\Jobs\SendPushNotification;
use Illuminate\Notifications\Notification;

class PushChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toPush') || ! method_exists($notifiable, 'getKey')) {
            return;
        }

        $message = $notification->toPush($notifiable);
        if (! is_array($message) || empty($message['title']) || empty($message['body'])) {
            return;
        }

        SendPushNotification::dispatch((int) $notifiable->getKey(), $message);
    }
}
