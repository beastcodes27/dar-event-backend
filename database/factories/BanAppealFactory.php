<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class BanAppealFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->banned(), 'ban_version' => 1,
            'ban_reason' => 'Repeated community rule violations.',
            'message' => 'Please review my account restriction.', 'status' => 'pending',
        ];
    }
}
