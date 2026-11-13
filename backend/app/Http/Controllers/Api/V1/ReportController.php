<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\ReportController as WebReportController;
use App\Services\ReportScope;
use App\Services\SlaClock;
use App\Services\SlaMetrics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __invoke(Request $request, WebReportController $reports, ReportScope $scope, SlaClock $clock, SlaMetrics $slaMetrics): JsonResponse
    {
        /** @var View $view */
        $view = $reports($request, $scope, $clock, $slaMetrics);
        $data = $view->getData();

        return response()->json(['data' => [
            'filters' => $data['filters'],
            'summary' => [
                'total' => $data['total'], 'open' => $data['openCount'], 'in_progress' => $data['inProgressCount'],
                'resolved' => $data['resolvedCount'], 'closed' => $data['closedCount'], 'cancelled' => $data['cancelledCount'],
                'unassigned' => $data['unassignedCount'], 'near_due' => $data['nearDueCount'], 'overdue' => $data['overdueCount'],
                'paused' => $data['pausedCount'],
            ],
            'sla' => [
                'measured' => $data['measuredCount'], 'within' => $data['onTimeCount'], 'breached' => max(0, $data['measuredCount'] - $data['onTimeCount']),
                'within_percent' => $data['withinSlaPercent'], 'breached_percent' => $data['overSlaPercent'],
                'completed_late' => $data['lateCompletedCount'],
                'average_resolution_minutes' => $data['durationMetrics']['average_resolution_minutes'],
                'average_first_response_minutes' => $data['durationMetrics']['average_first_response_minutes'],
            ],
            'by_category' => $this->mapRows($data['byCategory'], fn ($row): array => [
                'category_id' => $row->category_id, 'category' => $row->category?->name, 'total' => (int) $row->total,
                'resolved' => (int) $row->resolved_count, 'late' => (int) $row->late_count, 'sla_measured' => (int) $row->sla_measured_count,
            ]),
            'by_priority' => $this->mapRows($data['byPriority'], fn ($row): array => ['priority' => $row->priority, 'total' => (int) $row->total]),
            'by_month' => $this->mapRows($data['byMonth'], fn ($row): array => ['month' => $row->month, 'total' => (int) $row->total, 'within_sla' => (int) $row->on_time, 'late' => (int) $row->late_count, 'sla_measured' => (int) $row->sla_measured_count]),
            'by_building' => $this->mapRows($data['byBuilding'], fn ($row): array => ['building_id' => $row->building_id, 'building' => $row->building?->name, 'total' => (int) $row->total]),
            'by_location' => $this->mapRows($data['byLocation'], fn ($row): array => ['location_id' => $row->location_id, 'location' => $row->location?->name, 'building' => $row->location?->building?->name, 'total' => (int) $row->total]),
            'by_team' => collect($data['byTeam'])->map(fn (array $row): array => ['team_id' => $row['team_id'], 'team' => $row['team']?->name, 'resolved' => $row['resolved_count'], 'average_hours' => $row['average_hours'], 'pending' => $row['pending_count']])->values(),
            'by_technician' => collect($data['byTechnician'])->map(fn (array $row): array => ['technician_id' => $row['technician_id'], 'technician' => $row['technician']?->name, 'resolved' => $row['resolved_count'], 'average_hours' => $row['average_hours']])->values(),
        ]]);
    }

    public function export(Request $request, WebReportController $reports): StreamedResponse
    {
        $this->authorizeReports($request);

        return $reports->export($request, app(ReportScope::class));
    }

    /** @template T @param iterable<T> $rows @param callable(T): array<string, mixed> $map @return list<array<string, mixed>> */
    private function mapRows(iterable $rows, callable $map): array
    {
        return collect($rows)->map($map)->values()->all();
    }
}
