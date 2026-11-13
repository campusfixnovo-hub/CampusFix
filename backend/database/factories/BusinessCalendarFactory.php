<?php

namespace Database\Factories;

use App\Models\BusinessCalendar;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BusinessCalendar> */
class BusinessCalendarFactory extends Factory
{
    protected $model = BusinessCalendar::class;

    public function definition(): array
    {
        return ['name' => fake()->unique()->words(3, true), 'timezone' => 'America/Manaus', 'is_default' => false, 'is_active' => true];
    }
}
