<?php

namespace App\Services;

use App\Models\{Team, TeamHistory, User};

class TeamAuditService
{
    public function record(Team $team, ?User $actor, string $event, array $old = [], array $new = [], ?int $subjectUserId = null, array $metadata = []): TeamHistory
    {
        return TeamHistory::create([
            'team_id' => $team->id,
            'actor_id' => $actor?->id,
            'subject_user_id' => $subjectUserId,
            'event_type' => $event,
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
            'metadata' => $metadata ?: null,
            'created_at' => now(),
        ]);
    }
}
