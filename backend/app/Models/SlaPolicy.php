<?php

namespace App\Models;

use App\Enums\TicketPriority;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SlaPolicy extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'priority', 'category_id', 'business_calendar_id', 'target_minutes', 'warn_before_minutes', 'effective_from', 'effective_until', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return ['priority' => TicketPriority::class, 'target_minutes' => 'integer', 'warn_before_minutes' => 'integer', 'effective_from' => 'date', 'effective_until' => 'date', 'is_active' => 'boolean'];
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function calendar()
    {
        return $this->belongsTo(BusinessCalendar::class, 'business_calendar_id');
    }

    public function tickets()
    {
        return $this->hasMany(Ticket::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
