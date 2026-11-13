<?php

namespace Database\Factories;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Campus;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Ticket> */
class TicketFactory extends Factory
{
    protected $model = Ticket::class;

    public function definition(): array
    {
        $number = fake()->unique()->numberBetween(1, 999999);

        return [
            'code' => 'CF-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT),
            'title' => fake()->sentence(6),
            'description' => fake()->paragraph(),
            'category_id' => Category::factory(),
            'priority' => fake()->randomElement(TicketPriority::cases())->value,
            'status' => TicketStatus::Open->value,
            'requester_id' => User::factory(),
            'campus_id' => Campus::factory(),
            'building_id' => null,
            'location_id' => null,
            'location_details' => fake()->optional()->sentence(),
            'team_id' => null,
            'assigned_user_id' => null,
            'sla_policy_id' => null,
            'sla_target_minutes' => null,
            'due_at' => now()->addDay(),
        ];
    }
}
