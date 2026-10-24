<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->unique()->sentence(4);
        $startsAt = now()->addDays(fake()->numberBetween(2, 60));

        return [
            'user_id' => User::factory(),
            'title' => $title,
            'slug' => Str::slug($title).'-'.fake()->unique()->numerify('###'),
            'description' => fake()->paragraph(),
            'category' => fake()->randomElement(Event::CATEGORIES),
            'location' => fake()->city().', '.fake()->countryCode(),
            'venue' => fake()->company(),
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHours(4),
            'price' => fake()->randomElement([0, 15, 25, 45, 80]),
            'currency' => 'USD',
            'has_video' => fake()->boolean(),
            'is_public' => true,
            'likes_count' => 0,
            'comments_count' => 0,
            'saves_count' => 0,
        ];
    }

    public function private(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_public' => false,
        ]);
    }
}
