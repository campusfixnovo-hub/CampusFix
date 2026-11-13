<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Campus extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'code', 'address', 'city', 'state', 'timezone', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function buildings()
    {
        return $this->hasMany(Building::class);
    }

    public function tickets()
    {
        return $this->hasMany(Ticket::class);
    }

    public function teams()
    {
        return $this->hasMany(Team::class);
    }
}
