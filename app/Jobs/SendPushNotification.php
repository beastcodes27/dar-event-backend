<?php

namespace App\Jobs;

use App\Models\User;
use App\Support\PushNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendPushNotification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array{title: string, body: string, data?: array<string, string>}  $message
     */
    public function __construct(
        public int $userId,
        public array $message,
    ) {}

    public function handle(PushNotifier $notifier): void
    {
        $user = User::find($this->userId);
        if ($user) {
            $notifier->send($user, $this->message);
        }
    }
}
