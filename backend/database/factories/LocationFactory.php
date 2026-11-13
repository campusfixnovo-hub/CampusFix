<?php

namespace Database\Factories;

use App\Models\Building;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Location> */
class LocationFactory extends Factory
{
    protected $model = Location::class;

    public function definition(): array
    {
        return ['building_id' => Building::factory(), 'name' => fake()->unique()->words(3, true), 'floor' => (string) fake()->numberBetween(0, 5), 'room' => (string) fake()->numberBetween(1, 40), 'details' => fake()->optional()->sentence(), 'is_active' => true];
    }
}
