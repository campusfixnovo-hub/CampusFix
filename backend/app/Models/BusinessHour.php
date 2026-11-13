<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BusinessHour extends Model
{
    use HasFactory;

    protected $fillable = ['business_calendar_id', 'weekday', 'segment', 'opens_at', 'closes_at'];

    protected function casts(): array
    {
        return ['weekday' => 'integer', 'segment' => 'integer'];
    }

    public function calendar()
    {
        return $this->belongsTo(BusinessCalendar::class, 'business_calendar_id');
    }
}
