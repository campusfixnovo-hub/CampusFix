<?php

namespace App\Models;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ticket extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code', 'title', 'description', 'category_id', 'priority', 'status', 'requester_id',
        'campus_id', 'building_id', 'location_id', 'location_details', 'team_id', 'assigned_user_id',
        'sla_policy_id', 'sla_target_minutes', 'due_at', 'first_response_at', 'sla_breached_at',
        'resolved_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'priority' => TicketPriority::class,
            'status' => TicketStatus::class,
            'sla_target_minutes' => 'integer',
            'due_at' => 'datetime',
            'first_response_at' => 'datetime',
            'sla_breached_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function assignedTechnician()
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function campus()
    {
        return $this->belongsTo(Campus::class);
    }

    public function building()
    {
        return $this->belongsTo(Building::class);
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function slaPolicy()
    {
        return $this->belongsTo(SlaPolicy::class);
    }

    public function histories()
    {
        return $this->hasMany(TicketHistory::class)->orderBy('created_at')->orderBy('id');
    }

    public function comments()
    {
        return $this->hasMany(Comment::class);
    }

    public function attachments()
    {
        return $this->hasMany(Attachment::class);
    }

    public function rating()
    {
        return $this->hasOne(Rating::class);
    }

    public function slaPauses()
    {
        return $this->hasMany(TicketSlaPause::class);
    }
}
