<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BusinessCalendar extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'timezone', 'is_default', 'is_active'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_active' => 'boolean'];
    }

    public function hours()
    {
        return $this->hasMany(BusinessHour::class, 'business_calendar_id')->orderBy('weekday')->orderBy('segment');
    }

    public function holidays()
    {
        return $this->hasMany(BusinessHoliday::class, 'business_calendar_id');
    }

    public function slaPolicies()
    {
        return $this->hasMany(SlaPolicy::class, 'business_calendar_id');
    }
}
