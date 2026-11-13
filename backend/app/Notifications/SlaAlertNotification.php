<?php

namespace App\Notifications;

use App\Models\Ticket;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class SlaAlertNotification extends Notification
{
    use Queueable;

    public function __construct(public Ticket $ticket, public string $event, public CarbonInterface $deadline, int $recipientId)
    {
        $hex = substr(hash('sha256', implode('|', [$recipientId, $ticket->id, $event, $deadline->toISOString()])), 0, 32);
        $hex[12] = '5';
        $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);
        $this->id = implode('-', [substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12)]);
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, int|string|null> */
    public function toDatabase(object $notifiable): array
    {
        $nearDue = $this->event === 'near_due';

        return [
            'event' => $this->event,
            'message' => $nearDue ? 'O prazo SLA deste chamado está próximo.' : 'O prazo SLA deste chamado foi ultrapassado.',
            'ticket_id' => $this->ticket->id,
            'ticket_code' => $this->ticket->code,
            'ticket_title' => $this->ticket->title,
            'deadline' => $this->deadline->toIso8601String(),
            'url' => route('tickets.show', $this->ticket),
        ];
    }
}
