<?php

namespace Database\Factories;

use App\Enums\TicketAuditEvent;
use App\Models\Ticket;
use App\Models\TicketHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TicketHistory> */
class TicketHistoryFactory extends Factory
{
    protected $model = TicketHistory::class;

    public function definition(): array
    {
        return ['ticket_id' => Ticket::factory(), 'actor_id' => User::factory(), 'event_type' => TicketAuditEvent::Created->value, 'source' => 'web', 'old_values' => null, 'new_values' => null, 'metadata' => null, 'created_at' => now()];
    }
}
