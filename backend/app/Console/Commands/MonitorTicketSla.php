<?php

namespace App\Console\Commands;

use App\Enums\TicketAuditEvent;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\SlaAlertNotification;
use App\Services\SlaClock;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MonitorTicketSla extends Command
{
    protected $signature = 'campusfix:sla-monitor';

    protected $description = 'Registra violações SLA e envia notificações de prazo';

    public function handle(SlaClock $clock): int
    {
        $now = now();
        $breaches = $this->recordBreaches($now);
        $nearDueNotifications = $this->notifyNearDue($clock, $now);
        $overdueNotifications = $this->notifyOverdue($now);

        $this->components->info("SLA verificado: {$breaches} violações registradas, {$nearDueNotifications} alertas próximos e {$overdueNotifications} alertas de atraso adicionados.");

        return self::SUCCESS;
    }

    private function recordBreaches(CarbonInterface $now): int
    {
        $updatedCount = 0;
        $this->breachCandidates($now)->whereNull('sla_breached_at')->select('tickets.id as id')->chunkById(200, function ($rows) use ($now, &$updatedCount): void {
            $ids = $rows->pluck('id');
            $updatedCount += DB::transaction(function () use ($ids, $now): int {
                $eligible = $this->breachCandidates($now)->whereNull('sla_breached_at')->whereIn('tickets.id', $ids)
                    ->select(['tickets.id', 'tickets.due_at'])->lockForUpdate()->get();
                if ($eligible->isEmpty()) {
                    return 0;
                }

                $eligibleIds = $eligible->pluck('id');
                $createdAt = now();
                $updated = Ticket::query()->whereIn('id', $eligibleIds)->whereNull('sla_breached_at')
                    ->update(['sla_breached_at' => DB::raw('due_at'), 'updated_at' => $createdAt]);
                if ($updated === 0) {
                    return 0;
                }

                $auditRows = $eligible->map(fn ($ticket): array => [
                    'ticket_id' => $ticket->id,
                    'actor_id' => null,
                    'event_type' => TicketAuditEvent::SlaBreached->value,
                    'source' => 'system',
                    'old_values' => json_encode(['sla_breached_at' => null], JSON_THROW_ON_ERROR),
                    'new_values' => json_encode(['sla_breached_at' => CarbonImmutable::parse($ticket->due_at)->toISOString(), 'due_at' => CarbonImmutable::parse($ticket->due_at)->toISOString()], JSON_THROW_ON_ERROR),
                    'created_at' => $createdAt,
                ])->all();
                DB::table('ticket_histories')->insert($auditRows);

                return $updated;
            });
        }, 'id');

        return $updatedCount;
    }

    private function breachCandidates(CarbonInterface $now): Builder
    {
        return Ticket::query()->whereNotNull('due_at')->where('due_at', '<=', $now)
            ->where(function ($query): void {
                $query->where(function ($active): void {
                    $active->whereNull('resolved_at')->whereIn('status', ['open', 'in_triage', 'awaiting_service', 'in_progress', 'awaiting_material', 'reopened'])
                        ->whereDoesntHave('slaPauses', fn ($pauses) => $pauses->whereNull('ended_at'));
                })->orWhere(function ($completed): void {
                    $completed->whereNotNull('resolved_at')->whereColumn('resolved_at', '>', 'due_at');
                });
            });
    }

    private function notifyNearDue(SlaClock $clock, CarbonInterface $now): int
    {
        $count = 0;
        Ticket::query()->whereIn('status', ['open', 'in_triage', 'awaiting_service', 'in_progress', 'awaiting_material', 'reopened'])
            ->whereNotNull('due_at')->where('due_at', '>', $now)->whereDoesntHave('slaPauses', fn ($pauses) => $pauses->whereNull('ended_at'))
            ->with(['slaPolicy.calendar', 'requester', 'assignedTechnician'])->select('tickets.*')->chunkById(200, function ($tickets) use ($clock, $now, &$count): void {
                $nearDue = collect();
                foreach ($tickets as $ticket) {
                    $calendar = $ticket->slaPolicy?->calendar;
                    $minutes = $calendar
                        ? $clock->businessMinutesBetween(CarbonImmutable::parse($now), CarbonImmutable::parse($ticket->due_at), $calendar)
                        : (int) CarbonImmutable::parse($now)->diffInMinutes(CarbonImmutable::parse($ticket->due_at));
                    if ($minutes >= 0 && $minutes <= ($ticket->slaPolicy?->warn_before_minutes ?? 240)) {
                        $nearDue->push($ticket);
                    }
                }
                $count += $this->notifyBatch($nearDue, 'near_due', fn (Ticket $ticket): CarbonImmutable => CarbonImmutable::parse($ticket->due_at));
            });

        return $count;
    }

    private function notifyOverdue(CarbonInterface $now): int
    {
        $count = 0;
        $this->breachCandidates($now)->whereNotNull('sla_breached_at')->with(['requester', 'assignedTechnician', 'team'])->select('tickets.*')->chunkById(200, function ($tickets) use (&$count): void {
            $count += $this->notifyBatch($tickets, 'overdue', fn (Ticket $ticket): CarbonImmutable => CarbonImmutable::parse($ticket->sla_breached_at));
        }, 'id');

        return $count;
    }

    private function notifyBatch(Collection $tickets, string $event, \Closure $deadlineResolver): int
    {
        if ($tickets->isEmpty()) {
            return 0;
        }

        $campusIds = $tickets->pluck('campus_id')->unique()->values()->all();
        $teamIds = $tickets->pluck('team_id')->filter()->unique()->values()->all();
        $managerScope = fn ($teams) => $teams->where('team_members.member_role', 'manager')->where(function ($scope) use ($campusIds, $teamIds): void {
            $scope->whereIn('teams.campus_id', $campusIds)->orWhereIn('teams.id', $teamIds);
        });
        $managers = User::role('manager')->where('is_active', true)->whereHas('teams', $managerScope)
            ->with(['teams' => fn ($teams) => $managerScope($teams)->select(['teams.id', 'teams.campus_id'])])->get();
        $managersByCampus = [];
        $managersByTeam = [];
        foreach ($managers as $manager) {
            foreach ($manager->teams as $team) {
                if ($team->campus_id !== null && in_array((int) $team->campus_id, $campusIds, true)) {
                    $managersByCampus[$team->campus_id][] = $manager;
                }
                if (in_array((int) $team->id, $teamIds, true)) {
                    $managersByTeam[$team->id][] = $manager;
                }
            }
        }

        $inserted = 0;
        foreach ($tickets as $ticket) {
            $recipients = collect([$ticket->requester, $ticket->assignedTechnician])
                ->merge($managersByCampus[$ticket->campus_id] ?? [])
                ->merge($ticket->team_id ? ($managersByTeam[$ticket->team_id] ?? []) : [])
                ->filter(fn ($recipient) => $recipient instanceof User && $recipient->is_active)
                ->unique('id');

            foreach ($recipients as $recipient) {
                $notification = new SlaAlertNotification($ticket, $event, $deadlineResolver($ticket), (int) $recipient->id);
                try {
                    $recipient->notify($notification);
                    $inserted++;
                } catch (QueryException $exception) {
                    if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
                        throw $exception;
                    }
                }
            }
        }

        return $inserted;
    }
}
