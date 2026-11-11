<?php

namespace Database\Factories;

use App\Models\PublisherPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PublisherPlan> */
class PublisherPlanFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => 'Publisher monthly', 'description' => 'Publish events with DarEvent.', 'price' => 10000, 'currency' => 'TZS', 'duration_days' => 30, 'is_active' => true];
    }
}
