<?php

namespace App\Services;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class SlaMetrics
{
    public function nearDueCount(Builder $query, SlaClock $clock): int
    {
        $now = CarbonImmutable::now();
        $count = 0;
        $activeStatuses = [
            TicketStatus::Open->value, TicketStatus::InTriage->value, TicketStatus::AwaitingService->value,
            TicketStatus::InProgress->value, TicketStatus::AwaitingMaterial->value, TicketStatus::Reopened->value,
        ];

        (clone $query)->whereIn('status', $activeStatuses)->whereNotNull('due_at')->where('due_at', '>', $now)
            ->whereDoesntHave('slaPauses', fn ($pauses) => $pauses->whereNull('ended_at'))
            ->with('slaPolicy.calendar')->select('tickets.*')->chunkById(200, function ($tickets) use ($clock, $now, &$count): void {
                foreach ($tickets as $ticket) {
                    $calendar = $ticket->slaPolicy?->calendar;
                    $minutes = $calendar
                        ? $clock->businessMinutesBetween($now, CarbonImmutable::parse($ticket->due_at), $calendar)
                        : (int) $now->diffInMinutes(CarbonImmutable::parse($ticket->due_at));
                    if ($minutes >= 0 && $minutes <= ($ticket->slaPolicy?->warn_before_minutes ?? 240)) {
                        $count++;
                    }
                }
            });

        return $count;
    }
}
