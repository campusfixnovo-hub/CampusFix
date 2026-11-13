<?php

namespace App\Enums;

enum TicketStatus: string
{
    case Open = 'open';
    case InTriage = 'in_triage';
    case AwaitingService = 'awaiting_service';
    case InProgress = 'in_progress';
    case AwaitingMaterial = 'awaiting_material';
    case AwaitingRequester = 'awaiting_requester';
    case Resolved = 'resolved';
    case Closed = 'closed';
    case Reopened = 'reopened';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Aberta',
            self::InTriage => 'Em triagem',
            self::AwaitingService => 'Aguardando atendimento',
            self::InProgress => 'Em andamento',
            self::AwaitingMaterial => 'Aguardando material',
            self::AwaitingRequester => 'Aguardando solicitante',
            self::Resolved => 'Resolvida',
            self::Closed => 'Encerrada',
            self::Reopened => 'Reaberta',
            self::Cancelled => 'Cancelada',
        };
    }

    public function pausesSla(): bool
    {
        return $this === self::AwaitingRequester;
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Closed, self::Cancelled], true);
    }
}
