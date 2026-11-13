<?php

namespace Database\Factories;

use App\Models\Rating;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Rating> */
class RatingFactory extends Factory
{
    protected $model = Rating::class;

    public function definition(): array
    {
        return ['ticket_id' => Ticket::factory(), 'user_id' => User::factory(), 'score' => fake()->numberBetween(3, 5), 'comment' => fake()->optional()->sentence()];
    }
}
