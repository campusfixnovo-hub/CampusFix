<?php

namespace Database\Factories;

use App\Models\Campus;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Team> */
class TeamFactory extends Factory
{
    protected $model = Team::class;

    public function definition(): array
    {
        return ['campus_id' => Campus::factory(), 'name' => fake()->unique()->words(2, true).' Team', 'description' => fake()->optional()->sentence(), 'is_active' => true];
    }
}
