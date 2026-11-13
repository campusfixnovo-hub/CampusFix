<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeamHistory extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['team_id','actor_id','subject_user_id','event_type','old_values','new_values','metadata','created_at'];

    protected function casts(): array
    {
        return ['old_values'=>'array','new_values'=>'array','metadata'=>'array','created_at'=>'datetime'];
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function subject()
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }
}
