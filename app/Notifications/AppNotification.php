<?php

namespace App\Notifications;

use App\Notifications\Channels\PushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class AppNotification extends Notification
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $type,
        public array $payload = [],
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', PushChannel::class];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return array_merge([
            'type' => $this->type,
        ], $this->payload);
    }

    /**
     * @return array{title: string, body: string, data: array<string, string>}
     */
    public function toPush(object $notifiable): array
    {
        $title = match ($this->type) {
            'event_like' => 'New like on your post',
            'comment_like' => 'New like on your comment',
            'comment' => 'New comment',
            'comment_reply' => 'New reply',
            'follow' => 'New follower',
            'message' => 'New message',
            'login' => 'New login detected',
            default => 'Veylo',
        };

        $data = ['type' => $this->type];
        foreach (['actor' => 'actor_id', 'event' => 'event_id', 'comment' => 'comment_id'] as $key => $field) {
            $id = $this->payload[$key]['id'] ?? null;
            if ($id !== null) {
                $data[$field] = (string) $id;
            }
        }
        if (isset($this->payload['conversation']['username'])) {
            $data['username'] = (string) $this->payload['conversation']['username'];
        }

        return [
            'title' => $title,
            'body' => (string) ($this->payload['message'] ?? 'You have a new notification.'),
            'data' => $data,
        ];
    }
}
