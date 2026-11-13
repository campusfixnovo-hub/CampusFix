<?php

namespace Database\Seeders;

use App\Models\Building;
use App\Models\BusinessCalendar;
use App\Models\BusinessHour;
use App\Models\Campus;
use App\Models\Category;
use App\Models\Location;
use App\Models\Team;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CampusFixReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $campus = Campus::firstOrCreate(['code' => 'CAMPUS-CENTRAL'], [
            'name' => 'Campus Central', 'address' => 'Av. Universitária, 1000', 'city' => 'Manaus', 'state' => 'AM',
            'timezone' => 'America/Manaus', 'is_active' => true,
        ]);

        $buildings = [
            'BL-A' => 'Bloco Acadêmico A',
            'BL-B' => 'Biblioteca e Serviços',
        ];

        foreach ($buildings as $code => $name) {
            $building = Building::firstOrCreate(['campus_id' => $campus->id, 'code' => $code], ['name' => $name, 'is_active' => true]);
            $places = $code === 'BL-A'
                ? [['Laboratório de Informática 1', '1', '101'], ['Sala de Aula A-203', '2', '203']]
                : [['Biblioteca - Atendimento', '1', 'B-01'], ['Sanitário acessível', 'Térreo', 'B-04']];

            foreach ($places as [$place, $floor, $room]) {
                Location::firstOrCreate(['building_id' => $building->id, 'name' => $place], ['floor' => $floor, 'room' => $room, 'is_active' => true]);
            }
        }

        $categories = [
            'Elétrica', 'Hidráulica', 'Ar-condicionado', 'Informática', 'Mobiliário',
            'Limpeza', 'Pintura', 'Estrutura predial', 'Segurança e acessibilidade', 'Outros',
        ];

        foreach ($categories as $name) {
            Category::firstOrCreate(['slug' => Str::slug($name)], ['name' => $name, 'is_active' => true]);
        }

        foreach (['Equipe de Elétrica', 'Equipe de Infraestrutura'] as $name) {
            Team::firstOrCreate(['campus_id' => $campus->id, 'name' => $name], ['description' => 'Equipe de atendimento do Campus Central', 'is_active' => true]);
        }

        $calendar = BusinessCalendar::firstOrCreate(['name' => 'Calendário institucional padrão'], [
            'timezone' => 'America/Manaus', 'is_default' => true, 'is_active' => true,
        ]);

        foreach (range(1, 5) as $weekday) {
            foreach ([['08:00:00', '12:00:00'], ['13:00:00', '17:00:00']] as $segment => [$opensAt, $closesAt]) {
                BusinessHour::firstOrCreate(
                    ['business_calendar_id' => $calendar->id, 'weekday' => $weekday, 'segment' => $segment + 1],
                    ['opens_at' => $opensAt, 'closes_at' => $closesAt],
                );
            }
        }
    }
}
