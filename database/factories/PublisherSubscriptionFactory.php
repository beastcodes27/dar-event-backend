<?php

namespace Database\Factories;

use App\Models\PublisherPlan;
use App\Models\PublisherSubscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PublisherSubscription> */
class PublisherSubscriptionFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory()->verified(), 'publisher_plan_id' => PublisherPlan::factory(), 'status' => 'active', 'starts_at' => now(), 'ends_at' => now()->addDays(30)];
    }
}
