<?php

namespace Database\Factories;

use App\Enums\TicketPriority;
use App\Models\BusinessCalendar;
use App\Models\Category;
use App\Models\SlaPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SlaPolicy> */
class SlaPolicyFactory extends Factory
{
    protected $model = SlaPolicy::class;

    public function definition(): array
    {
        $priority = fake()->randomElement(TicketPriority::cases());

        return ['name' => $priority->label().' response', 'priority' => $priority->value, 'category_id' => null, 'business_calendar_id' => BusinessCalendar::factory(), 'target_minutes' => 1440, 'warn_before_minutes' => 60, 'effective_from' => now()->toDateString(), 'effective_until' => null, 'is_active' => true, 'created_by' => null];
    }

    public function forCategory(Category $category): static
    {
        return $this->state(fn (array $attributes) => ['category_id' => $category->id]);
    }
}
