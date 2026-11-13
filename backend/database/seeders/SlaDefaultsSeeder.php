<?php

namespace Database\Seeders;

use App\Enums\TicketPriority;
use App\Models\BusinessCalendar;
use App\Models\SlaPolicy;
use Illuminate\Database\Seeder;

class SlaDefaultsSeeder extends Seeder
{
    public function run(): void
    {
        $calendar = BusinessCalendar::where('is_default', true)->firstOrFail();
        $targets = [
            TicketPriority::Critical->value => ['name' => 'Crítica - 4 horas', 'minutes' => 240],
            TicketPriority::High->value => ['name' => 'Alta - 12 horas', 'minutes' => 720],
            TicketPriority::Medium->value => ['name' => 'Média - 24 horas', 'minutes' => 1440],
            TicketPriority::Low->value => ['name' => 'Baixa - 72 horas', 'minutes' => 4320],
        ];

        foreach ($targets as $priority => $target) {
            SlaPolicy::firstOrCreate(
                ['priority' => $priority, 'category_id' => null, 'effective_from' => '2026-01-01'],
                [
                    'name' => $target['name'], 'business_calendar_id' => $calendar->id,
                    'target_minutes' => $target['minutes'], 'warn_before_minutes' => 60, 'is_active' => true,
                ],
            );
        }
    }
}
