<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ContentReportFactory extends Factory
{
    public function definition(): array
    {
        return ['reporter_id' => User::factory(), 'event_id' => Event::factory(), 'target_type' => 'event', 'target_id' => fn (array $attributes) => $attributes['event_id'], 'reported_user_id' => fn (array $attributes) => Event::withoutGlobalScopes()->findOrFail($attributes['event_id'])->user_id, 'reason' => 'spam', 'snapshot' => ['title' => 'Reported event', 'body' => 'Event information at submission'], 'status' => 'pending'];
    }
}
