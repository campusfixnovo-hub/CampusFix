<?php

namespace App\Services;

use App\Models\BusinessCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class SlaClock
{
    /** @var array<string, list<array{open: CarbonImmutable, close: CarbonImmutable}>> */
    private array $scheduleCache = [];

    public function addBusinessMinutes(CarbonImmutable $at, int $minutes, BusinessCalendar $calendar): CarbonImmutable
    {
        if (! $calendar->hours()->exists() && ! $calendar->holidays()->where('is_working_day', true)->whereNotNull('opens_at')->whereNotNull('closes_at')->exists()) {
            throw ValidationException::withMessages(['sla' => 'O calendário SLA não possui horário de atendimento configurado.']);
        }
        $cursor = $at->setTimezone($calendar->timezone)->startOfMinute();
        while ($minutes > 0) {
            if (! $this->isBusinessMinute($cursor, $calendar)) {
                $cursor = $cursor->addMinute();

                continue;
            }
            $minutes--;
            $cursor = $cursor->addMinute();
        }

        return $cursor;
    }

    public function businessMinutesBetween(CarbonImmutable $from, CarbonImmutable $to, BusinessCalendar $calendar): int
    {
        $start = $from->setTimezone($calendar->timezone)->startOfMinute();
        $end = $to->setTimezone($calendar->timezone)->startOfMinute();
        if ($end <= $start) {
            return 0;
        }

        $minutes = 0;
        for ($day = $start->startOfDay(); $day <= $end->startOfDay(); $day = $day->addDay()) {
            foreach ($this->segmentsForDate($day, $calendar) as $segment) {
                $segmentStart = $start > $segment['open'] ? $start : $segment['open'];
                $segmentEnd = $end < $segment['close'] ? $end : $segment['close'];
                if ($segmentEnd > $segmentStart) {
                    $minutes += (int) $segmentStart->diffInMinutes($segmentEnd);
                }
            }
        }

        return $minutes;
    }

    private function isBusinessMinute(CarbonImmutable $time, BusinessCalendar $calendar): bool
    {
        foreach ($this->segmentsForDate($time, $calendar) as $segment) {
            if ($time >= $segment['open'] && $time < $segment['close']) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{open: CarbonImmutable, close: CarbonImmutable}> */
    private function segmentsForDate(CarbonImmutable $time, BusinessCalendar $calendar): array
    {
        $key = $calendar->id.':'.$time->toDateString();
        if (! array_key_exists($key, $this->scheduleCache)) {
            $holiday = $calendar->holidays()->whereDate('holiday_date', $time->toDateString())->first();
            if ($holiday && ! $holiday->is_working_day) {
                $this->scheduleCache[$key] = [];
            } else {
                $segments = $holiday && $holiday->is_working_day
                    ? collect([['opens_at' => $holiday->opens_at, 'closes_at' => $holiday->closes_at]])
                    : $calendar->hours()->where('weekday', $time->isoWeekday())->get();
                $this->scheduleCache[$key] = collect($segments)->filter(fn ($s) => $s['opens_at'] && $s['closes_at'])
                    ->map(fn ($s) => [
                        'open' => CarbonImmutable::parse($time->toDateString().' '.$s['opens_at'], $calendar->timezone),
                        'close' => CarbonImmutable::parse($time->toDateString().' '.$s['closes_at'], $calendar->timezone),
                    ])->all();
            }
        }

        return $this->scheduleCache[$key];
    }
}
