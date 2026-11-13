<?php

namespace App\Services;

use App\Enums\TicketAuditEvent;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\TicketHistory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class TicketHistoryPresenter
{
    /** @return Collection<int, array{id: int, event: string|null, label: string, description: string, actor: string, occurred_at: string|null}> */
    public function present(iterable $events, Ticket $ticket): Collection
    {
        $events = collect($events);
        $teamIds = $events->flatMap(fn (TicketHistory $event) => [
            data_get($event->old_values, 'team_id'), data_get($event->new_values, 'team_id'),
        ])->filter(fn ($id) => is_numeric($id) && (int) $id > 0)->unique()->values();
        $userIds = $events->flatMap(fn (TicketHistory $event) => [
            data_get($event->old_values, 'assigned_user_id'), data_get($event->new_values, 'assigned_user_id'),
        ])->filter(fn ($id) => is_numeric($id) && (int) $id > 0)->unique()->values();
        $teams = $teamIds->isEmpty() ? collect() : Team::query()->whereKey($teamIds)->pluck('name', 'id');
        $users = $userIds->isEmpty() ? collect() : User::query()->whereKey($userIds)->pluck('name', 'id');
        $timezone = $ticket->slaPolicy?->calendar?->timezone ?: config('app.timezone', 'America/Manaus');

        return $events->map(function (TicketHistory $event) use ($ticket, $teams, $users, $timezone): array {
            $type = $event->event_type;
            $old = $event->old_values ?? [];
            $new = $event->new_values ?? [];
            $actor = $event->actor?->name ?? 'Sistema';
            $teamName = fn ($id) => $id ? ($teams->get((int) $id) ?? 'Equipe anterior') : null;
            $userName = fn ($id) => $id ? ($users->get((int) $id) ?? 'Técnico anterior') : null;
            $date = fn ($value) => $this->dateLabel($value, $timezone);

            [$label, $description] = match ($type) {
                TicketAuditEvent::Created => ['Solicitação criada', isset($new['due_at']) ? 'Prazo inicialmente previsto para '.$date($new['due_at']).'.' : 'A solicitação foi registrada.'],
                TicketAuditEvent::TeamAssigned => ['Equipe atribuída', ! empty($new['team_id']) ? 'Equipe atribuída: '.$teamName($new['team_id']).'.' : 'A equipe foi removida da solicitação.'],
                TicketAuditEvent::TechnicianAssigned => ['Técnico atribuído', ! empty($new['assigned_user_id']) ? 'Técnico responsável: '.$userName($new['assigned_user_id']).'.' : 'O técnico foi removido da solicitação.'],
                TicketAuditEvent::StatusChanged => ['Status atualizado', $this->statusDescription($new['status'] ?? null)],
                TicketAuditEvent::Resolved => ['Solicitação resolvida', (int) ($new['assigned_user_id'] ?? 0) === (int) ($event->actor_id ?? 0) && ! empty($new['assigned_user_id'])
                    ? 'Solicitação resolvida pelo técnico responsável.' : 'Solicitação resolvida por '.$actor.'.'],
                TicketAuditEvent::Closed => ['Solicitação encerrada', 'A solicitação foi encerrada.'],
                TicketAuditEvent::Reopened => ['Solicitação reaberta', 'A solicitação foi reaberta para novo atendimento.'],
                TicketAuditEvent::Cancelled => ['Solicitação cancelada', 'A solicitação foi cancelada.'],
                TicketAuditEvent::DetailsUpdated => ['Detalhes atualizados', 'Os detalhes da solicitação foram atualizados.'],
                TicketAuditEvent::PriorityChanged => ['Prioridade atualizada', $this->priorityDescription($new['priority'] ?? null, $old['due_at'] ?? null, $new['due_at'] ?? null, $date)],
                TicketAuditEvent::LocationChanged => ['Localização atualizada', 'O local da solicitação foi atualizado.'],
                TicketAuditEvent::CommentAdded => [! empty($new['internal']) ? 'Nota interna registrada' : 'Atualização na conversa', ! empty($new['internal']) ? 'A equipe registrou uma atualização interna.' : 'Uma nova mensagem foi adicionada à conversa.'],
                TicketAuditEvent::SlaPaused => ['Prazo pausado', 'A contagem do prazo foi pausada enquanto aguarda resposta do solicitante.'],
                TicketAuditEvent::SlaResumed => ['Prazo retomado', $this->slaResumeDescription($old['due_at'] ?? null, $new['due_at'] ?? null, $date)],
                TicketAuditEvent::SlaBreached => ['Prazo excedido', 'O prazo de atendimento foi ultrapassado.'],
                TicketAuditEvent::Rated => ['Atendimento avaliado', isset($event->metadata['score']) ? 'Avaliação recebida: '.$event->metadata['score'].' de 5 estrelas.' : 'A solicitação recebeu uma avaliação.'],
                TicketAuditEvent::AttachmentAdded => ['Anexo adicionado', 'Um arquivo foi anexado à solicitação.'],
                default => ['Atualização da solicitação', 'O andamento da solicitação foi atualizado.'],
            };

            return [
                'id' => (int) $event->id,
                'event' => $type?->value,
                'label' => $label,
                'description' => $description,
                'actor' => $actor,
                'occurred_at' => $event->created_at?->copy()->setTimezone($timezone)->toIso8601String(),
            ];
        });
    }

    private function statusDescription(?string $status): string
    {
        $label = $status ? TicketStatus::tryFrom($status)?->label() : null;

        return $label ? 'Status alterado para: '.$label.'.' : 'O status da solicitação foi atualizado.';
    }

    private function priorityDescription(?string $priority, mixed $oldDueAt, mixed $newDueAt, callable $date): string
    {
        $label = $priority ? TicketPriority::tryFrom($priority)?->label() : null;
        $description = $label ? 'Prioridade alterada para: '.$label.'.' : 'A prioridade da solicitação foi atualizada.';
        if ($oldDueAt && $newDueAt && $oldDueAt !== $newDueAt) {
            $description .= ' Prazo anterior: '.$date($oldDueAt).'; novo prazo: '.$date($newDueAt).'.';
        }

        return $description;
    }

    private function slaResumeDescription(mixed $oldDueAt, mixed $newDueAt, callable $date): string
    {
        if ($newDueAt) {
            return 'A contagem foi retomada. Prazo atualizado para '.$date($newDueAt).($oldDueAt ? ' (prazo anterior: '.$date($oldDueAt).').' : '.');
        }

        return 'A contagem do prazo foi retomada.';
    }

    private function dateLabel(mixed $value, string $timezone): string
    {
        if (! $value) {
            return 'data indisponível';
        }

        return CarbonImmutable::parse($value)->setTimezone($timezone)->format('d/m/Y H:i');
    }
}
