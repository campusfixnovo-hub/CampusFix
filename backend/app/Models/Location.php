<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Location extends Model
{
    use HasFactory;

    protected $fillable = ['building_id', 'name', 'floor', 'room', 'details', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function building()
    {
        return $this->belongsTo(Building::class);
    }

    public function tickets()
    {
        return $this->hasMany(Ticket::class);
    }
}
