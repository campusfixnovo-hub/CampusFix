<?php

namespace App\Http\Controllers;

use App\Enums\TicketAuditEvent;
use App\Models\Building;
use App\Models\BusinessCalendar;
use App\Models\Campus;
use App\Models\Category;
use App\Models\Location;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ReportScope;
use App\Services\SlaMetrics;
use App\Services\SlaClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    private const ACTIVE_STATUSES = ['open', 'in_triage', 'awaiting_service', 'in_progress', 'awaiting_material', 'awaiting_requester', 'reopened'];

    public function __invoke(Request $request, ReportScope $scope, SlaClock $clock, SlaMetrics $slaMetrics): View
    {
        $this->authorizeReports($request->user());
        $filters = $this->filters($request, $scope);
        $query = $this->filteredQuery($request->user(), $scope, $filters);
        $now = now();

        $total = (clone $query)->count();
        $openCount = (clone $query)->whereIn('status', ['open', 'in_triage'])->count();
        $inProgressCount = (clone $query)->whereIn('status', ['awaiting_service', 'in_progress', 'awaiting_material', 'awaiting_requester', 'reopened'])->count();
        $resolvedCount = (clone $query)->where('status', 'resolved')->count();
        $closedCount = (clone $query)->where('status', 'closed')->count();
        $cancelledCount = (clone $query)->where('status', 'cancelled')->count();
        $unassignedCount = (clone $query)->whereIn('status', self::ACTIVE_STATUSES)->whereNull('assigned_user_id')->count();
        $pausedCount = (clone $query)->whereHas('slaPauses', fn ($pauses) => $pauses->whereNull('ended_at'))->count();
        $overdueQuery = fn () => (clone $query)->whereNotNull('due_at')->where('due_at', '<', $now)
            ->whereIn('status', self::ACTIVE_STATUSES)->where('status', '!=', 'awaiting_requester')
            ->whereDoesntHave('slaPauses', fn ($pauses) => $pauses->whereNull('ended_at'));
        $overdueCount = $overdueQuery()->count();
        $nearDueCount = $slaMetrics->nearDueCount($query, $clock);

        $completedWithSla = (clone $query)->whereNotNull('resolved_at')->whereNotNull('due_at')->whereNotIn('status', ['cancelled']);
        $onTimeCount = (clone $completedWithSla)->whereNull('sla_breached_at')->whereColumn('resolved_at', '<=', 'due_at')->count();
        $lateCompletedCount = (clone $completedWithSla)->where(function ($late): void {
            $late->whereNotNull('sla_breached_at')->orWhereColumn('resolved_at', '>', 'due_at');
        })->count();
        $breachedCount = (clone $query)->whereNotNull('due_at')->whereNotIn('status', ['cancelled'])->where(function ($late) use ($now): void {
            $late->whereNotNull('sla_breached_at')->orWhere(function ($done): void {
                $done->whereNotNull('resolved_at')->whereColumn('resolved_at', '>', 'due_at');
            })
                ->orWhere(function ($active) use ($now): void {
                    $active->whereNull('resolved_at')->whereIn('status', self::ACTIVE_STATUSES)->where('status', '!=', 'awaiting_requester')->where('due_at', '<', $now)->whereDoesntHave('slaPauses', fn ($pauses) => $pauses->whereNull('ended_at'));
                });
        })->count();
        $measuredCount = $onTimeCount + $breachedCount;
        $withinSlaPercent = $measuredCount > 0 ? round($onTimeCount * 100 / $measuredCount, 1) : null;
        $overSlaPercent = $measuredCount > 0 ? round($breachedCount * 100 / $measuredCount, 1) : null;

        $durationMetrics = $this->durationMetrics($query, $clock);
        $categoryExpression = $this->lateCaseSql();
        $measuredCase = $this->measuredCaseSql();
        $byCategory = (clone $query)->selectRaw("category_id, COUNT(*) AS total, SUM(CASE WHEN resolved_at IS NOT NULL AND status <> 'cancelled' THEN 1 ELSE 0 END) AS resolved_count, SUM($categoryExpression) AS late_count, SUM($measuredCase) AS sla_measured_count", [$now, $now])
            ->with('category')->groupBy('category_id')->orderByRaw('late_count / NULLIF(sla_measured_count, 0) DESC')->orderByDesc('sla_measured_count')->get();
        $byPriority = (clone $query)->selectRaw('priority, COUNT(*) AS total')->groupBy('priority')->orderBy('priority')->get();
        $byMonth = (clone $query)->selectRaw("DATE_FORMAT(created_at, '%Y-%m') AS month, COUNT(*) AS total, SUM(CASE WHEN status <> 'cancelled' AND resolved_at IS NOT NULL AND due_at IS NOT NULL AND resolved_at <= due_at AND sla_breached_at IS NULL THEN 1 ELSE 0 END) AS on_time, SUM($categoryExpression) AS late_count, SUM($measuredCase) AS sla_measured_count", [$now, $now])
            ->groupBy('month')->orderBy('month')->get();
        $byBuilding = (clone $query)->selectRaw('building_id, COUNT(*) AS total')->whereNotNull('building_id')->with('building')->groupBy('building_id')->orderByDesc('total')->limit(10)->get();
        $byLocation = (clone $query)->selectRaw('location_id, COUNT(*) AS total')->whereNotNull('location_id')->with('location.building')->groupBy('location_id')->orderByDesc('total')->limit(10)->get();

        $pendingStatuses = array_values(array_diff(self::ACTIVE_STATUSES, ['awaiting_requester']));
        $teamPending = (clone $query)->whereIn('status', $pendingStatuses)->selectRaw('team_id, COUNT(*) AS pending_count')
            ->groupBy('team_id')->get()->keyBy(fn ($row) => (string) $row->team_id);
        $teamMetricIds = collect(array_keys($durationMetrics['teams']))->merge($teamPending->keys())->filter(fn ($id) => $id !== '')->unique();
        $byTeam = $teamMetricIds->map(function (int|string $id) use ($durationMetrics, $teamPending): array {
            $metrics = $durationMetrics['teams'][$id] ?? ['sum' => 0, 'count' => 0];

            return ['team_id' => (int) $id, 'resolved_count' => $metrics['count'], 'average_hours' => $this->averageHours($metrics), 'pending_count' => (int) ($teamPending->get((string) $id)?->pending_count ?? 0)];
        })->values();
        $byTechnician = collect($durationMetrics['technicians'])->map(fn (array $metrics, int|string $id): array => [
            'technician_id' => (int) $id, 'resolved_count' => $metrics['count'], 'average_hours' => $this->averageHours($metrics),
        ])->values();
        $reportTeamIds = $scope->reportTeamIds($request->user());
        $teams = Team::query()->when($reportTeamIds !== null, fn ($q) => $q->whereIn('id', $reportTeamIds))->orderBy('name')->get();
        $teamNames = $teams->keyBy('id');
        $technicians = User::query()->whereIn('id', array_column($byTechnician->all(), 'technician_id'))->get()->keyBy('id');
        $teamPendingRows = $byTeam->map(fn (array $row) => $row + ['team' => $teamNames->get($row['team_id'])]);
        $technicianRows = $byTechnician->map(fn (array $row) => $row + ['technician' => $technicians->get($row['technician_id'])]);
        $byTeam = $teamPendingRows;
        $byTechnician = $technicianRows;

        $campuses = Campus::query()->when($scope->campusIds($request->user()) !== null, fn ($q) => $q->whereIn('id', $scope->campusIds($request->user())))->orderBy('name')->get();
        $buildings = Building::query()->when($scope->campusIds($request->user()) !== null, fn ($q) => $q->whereIn('campus_id', $scope->campusIds($request->user())))
            ->when($filters['campus_id'], fn ($q) => $q->where('campus_id', $filters['campus_id']))->orderBy('name')->get();
        $locations = Location::query()->whereHas('building', function ($buildingsQuery) use ($scope, $request, $filters): void {
            if ($scope->campusIds($request->user()) !== null) {
                $buildingsQuery->whereIn('campus_id', $scope->campusIds($request->user()));
            }
            if ($filters['campus_id']) {
                $buildingsQuery->where('campus_id', $filters['campus_id']);
            }
            if ($filters['building_id']) {
                $buildingsQuery->whereKey($filters['building_id']);
            }
        })->orderBy('name')->get();
        $categories = Category::query()->orderBy('name')->get();
        $filterTeams = Team::query()->when($reportTeamIds !== null, fn ($q) => $q->whereIn('id', $reportTeamIds))->orderBy('name')->get();
        $technicianIds = $scope->technicianIds($request->user());
        $filterTechnicians = User::role('technician')->where('is_active', true)->when($technicianIds !== null, fn ($q) => $q->whereIn('id', $technicianIds))->orderBy('name')->get();

        return view('reports.index', compact(
            'filters', 'total', 'openCount', 'inProgressCount', 'resolvedCount', 'closedCount', 'cancelledCount',
            'unassignedCount', 'nearDueCount', 'overdueCount', 'pausedCount', 'withinSlaPercent', 'overSlaPercent',
            'onTimeCount', 'lateCompletedCount', 'measuredCount', 'durationMetrics', 'byCategory', 'byPriority', 'byMonth',
            'byBuilding', 'byLocation', 'byTeam', 'byTechnician', 'campuses', 'buildings', 'locations', 'categories', 'filterTeams', 'filterTechnicians',
        ));
    }

    public function export(Request $request, ReportScope $scope): StreamedResponse
    {
        $this->authorizeReports($request->user());
        $filters = $this->filters($request, $scope);
        $query = $this->filteredQuery($request->user(), $scope, $filters)
            ->with(['campus', 'building', 'location', 'category', 'team', 'assignedTechnician'])
            ->withCount(['slaPauses as active_sla_pause_count' => fn ($pauses) => $pauses->whereNull('ended_at')])
            ->orderBy('tickets.id');

        return response()->streamDownload(function () use ($query): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Código', 'Título', 'Campus', 'Prédio', 'Local', 'Categoria', 'Prioridade', 'Status', 'Equipe', 'Técnico', 'Criado em', 'Primeira resposta', 'Resolvido em', 'Prazo SLA', 'Violado em', 'Pausado'], ',');
            (clone $query)->chunkById(300, function ($tickets) use ($output): void {
                foreach ($tickets as $ticket) {
                    $values = [
                        $ticket->code, $ticket->title, $ticket->campus?->name, $ticket->building?->name, $ticket->location?->name,
                        $ticket->category?->name, $ticket->priority?->label(), $ticket->status?->label(), $ticket->team?->name,
                        $ticket->assignedTechnician?->name, $ticket->created_at?->toDateTimeString(), $ticket->first_response_at?->toDateTimeString(),
                        $ticket->resolved_at?->toDateTimeString(), $ticket->due_at?->toDateTimeString(), $ticket->sla_breached_at?->toDateTimeString(),
                        $ticket->active_sla_pause_count > 0 ? 'Sim' : 'Não',
                    ];
                    fputcsv($output, array_map(fn ($value) => $this->safeCsvValue($value), $values), ',');
                }
            }, 'id');
            fclose($output);
        }, 'campusfix-relatorio-'.$filters['date_from'].'-'.$filters['date_to'].'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function authorizeReports(User $user): void
    {
        abort_unless($user->is_active && (
            ($user->hasRole('administrator') && $user->can('reports.view-all'))
            || ($user->hasAnyRole(['manager', 'technician']) && $user->can('reports.view-team'))
        ), 403);
    }

    /** @return array<string, mixed> */
    private function filters(Request $request, ReportScope $scope): array
    {
        $request->merge([
            'date_from' => $request->query('date_from', now()->subDays(29)->toDateString()),
            'date_to' => $request->query('date_to', now()->toDateString()),
        ]);
        $filters = $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'campus_id' => ['nullable', 'integer', Rule::exists('campuses', 'id')],
            'building_id' => ['nullable', 'integer', Rule::exists('buildings', 'id')],
            'location_id' => ['nullable', 'integer', Rule::exists('locations', 'id')],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'priority' => ['nullable', Rule::in(['low', 'medium', 'high', 'critical'])],
            'team_id' => ['nullable', 'integer', Rule::exists('teams', 'id')],
            'technician_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
        ]);
        $filters = array_merge(['campus_id' => null, 'building_id' => null, 'location_id' => null, 'category_id' => null, 'priority' => null, 'team_id' => null, 'technician_id' => null], $filters);

        $campusIds = $scope->campusIds($request->user());
        if ($filters['campus_id'] && $campusIds !== null && ! in_array((int) $filters['campus_id'], $campusIds, true)) {
            abort(403);
        }
        $teamIds = $scope->reportTeamIds($request->user());
        if ($filters['team_id'] && $teamIds !== null && ! in_array((int) $filters['team_id'], $teamIds, true)) {
            abort(403);
        }
        $technicianIds = $scope->technicianIds($request->user());
        if ($filters['technician_id'] && ($technicianIds !== null && ! in_array((int) $filters['technician_id'], $technicianIds, true))) {
            abort(403);
        }
        if ($filters['technician_id'] && ! User::role('technician')->whereKey($filters['technician_id'])->where('is_active', true)->exists()) {
            abort(403);
        }

        if ($filters['building_id']) {
            $building = Building::findOrFail($filters['building_id']);
            if (($filters['campus_id'] && (int) $building->campus_id !== (int) $filters['campus_id'])
                || ($campusIds !== null && ! in_array((int) $building->campus_id, $campusIds, true))) {
                abort(422, 'O prédio deve pertencer a um campus dentro do escopo selecionado.');
            }
        }
        if ($filters['location_id']) {
            $location = Location::with('building')->findOrFail($filters['location_id']);
            if (($filters['building_id'] && (int) $location->building_id !== (int) $filters['building_id'])
                || ($filters['campus_id'] && (int) $location->building->campus_id !== (int) $filters['campus_id'])
                || ($campusIds !== null && ! in_array((int) $location->building->campus_id, $campusIds, true))) {
                abort(422, 'O local deve pertencer ao prédio e campus dentro do escopo selecionado.');
            }
        }

        return $filters;
    }

    private function filteredQuery(User $user, ReportScope $scope, array $filters): Builder
    {
        return $scope->query($user)->whereBetween('tickets.created_at', [CarbonImmutable::parse($filters['date_from'])->startOfDay(), CarbonImmutable::parse($filters['date_to'])->endOfDay()])
            ->when($filters['campus_id'], fn ($q) => $q->where('campus_id', $filters['campus_id']))
            ->when($filters['building_id'], fn ($q) => $q->where('building_id', $filters['building_id']))
            ->when($filters['location_id'], fn ($q) => $q->where('location_id', $filters['location_id']))
            ->when($filters['category_id'], fn ($q) => $q->where('category_id', $filters['category_id']))
            ->when($filters['priority'], fn ($q) => $q->where('priority', $filters['priority']))
            ->when($filters['team_id'], fn ($q) => $q->where('team_id', $filters['team_id']))
            ->when($filters['technician_id'], fn ($q) => $q->where('assigned_user_id', $filters['technician_id']));
    }

    /** @return array{average_resolution_minutes: int|null, average_first_response_minutes: int|null, teams: array, technicians: array} */
    private function durationMetrics(Builder $query, SlaClock $clock): array
    {
        $result = ['resolution_sum' => 0, 'resolution_count' => 0, 'first_response_sum' => 0, 'first_response_count' => 0, 'teams' => [], 'technicians' => []];
        $defaultCalendar = BusinessCalendar::query()->where('is_default', true)->first();
        (clone $query)->where(fn ($tickets) => $tickets->whereNotNull('resolved_at')->orWhereNotNull('first_response_at'))
            ->with([
                'slaPolicy.calendar',
                'slaPauses',
                'histories' => fn ($histories) => $histories->where('event_type', TicketAuditEvent::Resolved)->select(['id', 'ticket_id', 'event_type', 'new_values']),
            ])->select('tickets.*')->chunkById(150, function ($tickets) use ($clock, $defaultCalendar, &$result): void {
                foreach ($tickets as $ticket) {
                    $calendar = $ticket->slaPolicy?->calendar ?? $defaultCalendar;
                    if ($ticket->resolved_at) {
                        $minutes = $this->businessElapsedMinutes($ticket, CarbonImmutable::parse($ticket->created_at), CarbonImmutable::parse($ticket->resolved_at), $calendar, $clock);
                        $result['resolution_sum'] += $minutes;
                        $result['resolution_count']++;
                        [$teamId, $technicianId] = $this->resolutionAssignment($ticket);
                        foreach ([['teams', $teamId], ['technicians', $technicianId]] as [$group, $id]) {
                            if ($id === null) {
                                continue;
                            }
                            $result[$group][$id] ??= ['sum' => 0, 'count' => 0];
                            $result[$group][$id]['sum'] += $minutes;
                            $result[$group][$id]['count']++;
                        }
                    }
                    if ($ticket->first_response_at) {
                        $result['first_response_sum'] += $this->businessElapsedMinutes($ticket, CarbonImmutable::parse($ticket->created_at), CarbonImmutable::parse($ticket->first_response_at), $calendar, $clock);
                        $result['first_response_count']++;
                    }
                }
            });

        return [
            'resolution_count' => $result['resolution_count'],
            'first_response_count' => $result['first_response_count'],
            'average_resolution_minutes' => $result['resolution_count'] ? (int) round($result['resolution_sum'] / $result['resolution_count']) : null,
            'average_first_response_minutes' => $result['first_response_count'] ? (int) round($result['first_response_sum'] / $result['first_response_count']) : null,
            'teams' => $result['teams'], 'technicians' => $result['technicians'],
        ];
    }

    private function businessElapsedMinutes(Ticket $ticket, CarbonImmutable $start, CarbonImmutable $end, ?BusinessCalendar $calendar, SlaClock $clock): int
    {
        $minutes = $calendar ? $clock->businessMinutesBetween($start, $end, $calendar) : (int) $start->diffInMinutes($end);
        foreach ($ticket->slaPauses as $pause) {
            $pauseStart = CarbonImmutable::parse($pause->started_at);
            if ($pauseStart >= $end) {
                continue;
            }
            $pauseEnd = $pause->ended_at ? CarbonImmutable::parse($pause->ended_at) : $end;
            $pauseStart = $pauseStart < $start ? $start : $pauseStart;
            $pauseEnd = $pauseEnd > $end ? $end : $pauseEnd;
            if ($pauseEnd <= $pauseStart) {
                continue;
            }
            $minutes -= $calendar ? $clock->businessMinutesBetween($pauseStart, $pauseEnd, $calendar) : (int) $pauseStart->diffInMinutes($pauseEnd);
        }

        return max(0, $minutes);
    }

    /** @return array{int|null, int|null} */
    private function resolutionAssignment(Ticket $ticket): array
    {
        $resolution = $ticket->histories->where('event_type', TicketAuditEvent::Resolved)->last();
        $snapshot = $resolution?->new_values ?? [];

        return [isset($snapshot['team_id']) ? (int) $snapshot['team_id'] : ($ticket->team_id ? (int) $ticket->team_id : null),
            isset($snapshot['assigned_user_id']) ? (int) $snapshot['assigned_user_id'] : ($ticket->assigned_user_id ? (int) $ticket->assigned_user_id : null)];
    }

    private function averageHours(array $metrics): ?float
    {
        return $metrics['count'] > 0 ? round($metrics['sum'] / $metrics['count'] / 60, 1) : null;
    }

    private function lateCaseSql(): string
    {
        return "CASE WHEN due_at IS NOT NULL AND status <> 'cancelled' AND (sla_breached_at IS NOT NULL OR (resolved_at IS NOT NULL AND resolved_at > due_at) OR (resolved_at IS NULL AND status IN ('open','in_triage','awaiting_service','in_progress','awaiting_material','reopened') AND due_at < ? AND NOT EXISTS (SELECT 1 FROM ticket_sla_pauses WHERE ticket_sla_pauses.ticket_id = tickets.id AND ticket_sla_pauses.ended_at IS NULL))) THEN 1 ELSE 0 END";
    }

    private function measuredCaseSql(): string
    {
        return "CASE WHEN due_at IS NOT NULL AND status <> 'cancelled' AND (resolved_at IS NOT NULL OR sla_breached_at IS NOT NULL OR (resolved_at IS NULL AND status IN ('open','in_triage','awaiting_service','in_progress','awaiting_material','reopened') AND due_at < ? AND NOT EXISTS (SELECT 1 FROM ticket_sla_pauses WHERE ticket_sla_pauses.ticket_id = tickets.id AND ticket_sla_pauses.ended_at IS NULL))) THEN 1 ELSE 0 END";
    }

    private function safeCsvValue(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return preg_match('/^[=+@\-\t\r]/u', $value) ? "'".$value : $value;
    }
}
