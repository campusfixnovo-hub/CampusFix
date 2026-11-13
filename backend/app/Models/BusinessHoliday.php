<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BusinessHoliday extends Model
{
    use HasFactory;

    protected $fillable = ['business_calendar_id', 'holiday_date', 'name', 'is_working_day', 'opens_at', 'closes_at'];

    protected function casts(): array
    {
        return ['holiday_date' => 'date', 'is_working_day' => 'boolean'];
    }

    public function calendar()
    {
        return $this->belongsTo(BusinessCalendar::class, 'business_calendar_id');
    }
}
