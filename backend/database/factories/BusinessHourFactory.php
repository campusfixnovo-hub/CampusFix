<?php

namespace Database\Factories;

use App\Models\BusinessCalendar;
use App\Models\BusinessHour;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BusinessHour> */
class BusinessHourFactory extends Factory
{
    protected $model = BusinessHour::class;

    public function definition(): array
    {
        return ['business_calendar_id' => BusinessCalendar::factory(), 'weekday' => fake()->numberBetween(1, 5), 'segment' => 1, 'opens_at' => '08:00:00', 'closes_at' => '17:00:00'];
    }
}
