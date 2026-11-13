<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TicketSlaPause extends Model
{
    use HasFactory;

    protected $fillable = ['ticket_id', 'started_by', 'ended_by', 'start_reason', 'end_reason', 'started_at', 'ended_at', 'duration_minutes'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime', 'duration_minutes' => 'integer'];
    }

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }

    public function startedBy()
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function endedBy()
    {
        return $this->belongsTo(User::class, 'ended_by');
    }
}
