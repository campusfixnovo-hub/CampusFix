<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Team extends Model
{
    use HasFactory;

    protected $fillable = ['campus_id', 'name', 'description', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function campus()
    {
        return $this->belongsTo(Campus::class);
    }

    public function members()
    {
        return $this->belongsToMany(User::class, 'team_members')
            ->withPivot(['member_role', 'joined_at'])
            ->wherePivotIn('member_role', ['technician', 'lead', 'manager'])
            ->withTimestamps();
    }

    public function allMemberships()
    {
        return $this->belongsToMany(User::class, 'team_members')
            ->withPivot(['member_role', 'joined_at'])
            ->withTimestamps();
    }

    public function technicians()
    {
        return $this->members()->wherePivotIn('member_role', ['technician', 'lead']);
    }

    public function leader()
    {
        return $this->members()->wherePivot('member_role', 'lead');
    }

    public function tickets()
    {
        return $this->hasMany(Ticket::class);
    }

    public function histories()
    {
        return $this->hasMany(TeamHistory::class)->orderBy('created_at')->orderBy('id');
    }
}
