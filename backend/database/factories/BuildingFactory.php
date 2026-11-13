<?php

namespace Database\Factories;

use App\Models\Building;
use App\Models\Campus;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Building> */
class BuildingFactory extends Factory
{
    protected $model = Building::class;

    public function definition(): array
    {
        return ['campus_id' => Campus::factory(), 'name' => fake()->unique()->words(2, true), 'code' => fake()->unique()->bothify('BLD-###'), 'is_active' => true];
    }
}
