<?php

namespace Database\Factories;

use App\Models\Ticket;
use App\Models\TicketSlaPause;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TicketSlaPause> */
class TicketSlaPauseFactory extends Factory
{
    protected $model = TicketSlaPause::class;

    public function definition(): array
    {
        return ['ticket_id' => Ticket::factory(), 'started_by' => User::factory(), 'ended_by' => null, 'start_reason' => 'Awaiting requester response', 'end_reason' => null, 'started_at' => now(), 'ended_at' => null, 'duration_minutes' => null];
    }

    public function resumed(): static
    {
        return $this->state(fn () => ['ended_by' => User::factory(), 'end_reason' => 'Requester responded', 'ended_at' => now(), 'duration_minutes' => 15]);
    }
}
