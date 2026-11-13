<?php

namespace Database\Factories;

use App\Models\BusinessCalendar;
use App\Models\BusinessHoliday;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BusinessHoliday> */
class BusinessHolidayFactory extends Factory
{
    protected $model = BusinessHoliday::class;

    public function definition(): array
    {
        return ['business_calendar_id' => BusinessCalendar::factory(), 'holiday_date' => fake()->dateTimeBetween('+1 day', '+1 year')->format('Y-m-d'), 'name' => fake()->sentence(3), 'is_working_day' => false, 'opens_at' => null, 'closes_at' => null];
    }
}
