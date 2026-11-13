<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Building extends Model
{
    use HasFactory;

    protected $fillable = ['campus_id', 'name', 'code', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function campus()
    {
        return $this->belongsTo(Campus::class);
    }

    public function locations()
    {
        return $this->hasMany(Location::class);
    }

    public function tickets()
    {
        return $this->hasMany(Ticket::class);
    }
}
