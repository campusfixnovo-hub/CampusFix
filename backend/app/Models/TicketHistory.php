<?php

namespace App\Models;

use App\Enums\TicketAuditEvent;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TicketHistory extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['ticket_id', 'actor_id', 'event_type', 'source', 'old_values', 'new_values', 'metadata', 'created_at'];

    protected function casts(): array
    {
        return ['event_type' => TicketAuditEvent::class, 'old_values' => 'array', 'new_values' => 'array', 'metadata' => 'array', 'created_at' => 'datetime'];
    }

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
