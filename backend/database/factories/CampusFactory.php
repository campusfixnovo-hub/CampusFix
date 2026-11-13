<?php

namespace Database\Factories;

use App\Models\Campus;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Campus> */
class CampusFactory extends Factory
{
    protected $model = Campus::class;

    public function definition(): array
    {
        return ['name' => fake()->unique()->city().' Campus', 'code' => fake()->unique()->bothify('CAMPUS-####'), 'address' => fake()->streetAddress(), 'city' => fake()->city(), 'state' => fake()->stateAbbr(), 'timezone' => 'America/Manaus', 'is_active' => true];
    }
}
