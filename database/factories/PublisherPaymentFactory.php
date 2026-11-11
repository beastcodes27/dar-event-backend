<?php

namespace Database\Factories;

use App\Models\PublisherPayment;
use App\Models\PublisherPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PublisherPayment> */
class PublisherPaymentFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory()->verified(), 'publisher_plan_id' => PublisherPlan::factory(), 'reference' => (string) Str::uuid(), 'amount' => 10000, 'currency' => 'TZS', 'duration_days' => 30, 'phone' => '255712345678', 'status' => 'pending', 'provider_order_id' => 'sp_'.Str::uuid()];
    }
}
