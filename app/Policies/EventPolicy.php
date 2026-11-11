<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\User;

class EventPolicy
{
    public function create(User $user): bool
    {
        return $user->canPublishEvents();
    }

    public function view(?User $user, Event $event): bool
    {
        return $event->is_public || $event->isHostedBy($user);
    }

    public function update(User $user, Event $event): bool
    {
        return $user->canPublishEvents() && $event->isHostedBy($user);
    }

    public function delete(User $user, Event $event): bool
    {
        return $event->isHostedBy($user);
    }
}
