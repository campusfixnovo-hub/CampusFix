<?php

namespace App\Services;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use Carbon\CarbonImmutable;

class TicketSlaPresenter
{
    /** @return array{state: string, deadline: string|null, message: string, detail: string|null, tone: string} */
    public function present(Ticket $ticket, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now(config('app.timezone', 'America/Manaus'));
        $calendar = $ticket->slaPolicy?->calendar;
        $timezone = $calendar?->timezone ?: config('app.timezone', 'America/Manaus');
        $dueAt = $ticket->due_at?->toImmutable()->setTimezone($timezone);

        if (! $dueAt) {
            return ['state' => 'none', 'deadline' => null, 'message' => 'Sem prazo de atendimento definido.', 'detail' => null, 'tone' => 'muted'];
        }

        $deadline = $dueAt->format('d/m/Y H:i');

        if ($ticket->status === TicketStatus::Cancelled) {
            return ['state' => 'cancelled', 'deadline' => $deadline, 'message' => 'Solicitação cancelada.', 'detail' => 'Prazo originalmente previsto: '.$deadline, 'tone' => 'muted'];
        }

        if (in_array($ticket->status, [TicketStatus::Resolved, TicketStatus::Closed], true)) {
            $completedAt = $ticket->resolved_at?->toImmutable() ?? $ticket->closed_at?->toImmutable();
            if (! $completedAt) {
                return ['state' => 'unknown', 'deadline' => $deadline, 'message' => 'Solicitação concluída.', 'detail' => 'Não há horário de conclusão registrado para comparar com o prazo.', 'tone' => 'muted'];
            }

            $completedAt = $completedAt->setTimezone($timezone);
            $onTime = $completedAt <= $dueAt;

            return [
                'state' => $onTime ? 'on_time' : 'late',
                'deadline' => $deadline,
                'message' => $onTime ? 'Concluída dentro do prazo.' : 'Concluída fora do prazo.',
                'detail' => 'Conclusão registrada em '.$completedAt->format('d/m/Y H:i').'. Prazo previsto: '.$deadline.'.',
                'tone' => $onTime ? 'success' : 'danger',
            ];
        }

        if ($ticket->status?->pausesSla()) {
            return ['state' => 'paused', 'deadline' => $deadline, 'message' => 'Contagem pausada enquanto aguarda resposta do solicitante.', 'detail' => 'Prazo previsto: '.$deadline.'.', 'tone' => 'paused'];
        }

        if (! $calendar) {
            return ['state' => 'calendar_unavailable', 'deadline' => $deadline, 'message' => 'Prazo de atendimento definido.', 'detail' => 'Vencimento previsto para '.$deadline.'.', 'tone' => 'muted'];
        }

        $nowAtCalendar = $now->setTimezone($timezone);
        $isOverdue = $nowAtCalendar > $dueAt;
        $minutes = $isOverdue
            ? app(SlaClock::class)->businessMinutesBetween($dueAt, $nowAtCalendar, $calendar)
            : app(SlaClock::class)->businessMinutesBetween($nowAtCalendar, $dueAt, $calendar);
        $duration = $this->duration($minutes);

        $message = $isOverdue
            ? ($minutes > 0 ? 'Prazo excedido há '.$duration.' de expediente.' : 'Prazo excedido.')
            : 'Restam '.$duration.' de expediente.';

        return [
            'state' => $isOverdue ? 'overdue' : 'active',
            'deadline' => $deadline,
            'message' => $message,
            'detail' => 'Vencimento previsto para '.$deadline.'.',
            'tone' => $isOverdue ? 'danger' : 'active',
        ];
    }

    private function duration(int $minutes): string
    {
        if ($minutes < 1) {
            return 'menos de 1 minuto';
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        if ($hours === 0) {
            return $remainingMinutes.' min';
        }

        return $remainingMinutes > 0 ? $hours.' h '.$remainingMinutes.' min' : $hours.' h';
    }
}
