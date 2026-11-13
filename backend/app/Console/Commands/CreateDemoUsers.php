<?php

namespace App\Console\Commands;

use App\Enums\UserType;
use App\Models\Campus;
use App\Models\Team;
use App\Models\User;
use App\Services\TeamAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

use function Laravel\Prompts\password as promptPassword;

class CreateDemoUsers extends Command
{
    protected $signature = 'campusfix:demo-users {--campus= : ID de um campus ativo (necessário somente quando houver mais de um)}';

    protected $description = 'Cria perfis fictícios de demonstração no ambiente local';

    /** @var array<string, array{name: string, role: string, user_type: UserType}> */
    private const DEMO_USERS = [
        'campusfix.demo.admin@example.test' => ['name' => 'CampusFix Demo Administrador', 'role' => 'administrator', 'user_type' => UserType::Administrator],
        'campusfix.demo.manager@example.test' => ['name' => 'CampusFix Demo Gestor', 'role' => 'manager', 'user_type' => UserType::Manager],
        'campusfix.demo.technician1@example.test' => ['name' => 'CampusFix Demo Técnico 1', 'role' => 'technician', 'user_type' => UserType::Technician],
        'campusfix.demo.technician2@example.test' => ['name' => 'CampusFix Demo Técnico 2', 'role' => 'technician', 'user_type' => UserType::Technician],
    ];

    public function handle(): int
    {
        $isTesting = app()->environment('testing');
        $expectedDatabase = $isTesting ? 'campusfix_test' : 'campusfix';

        if (! app()->environment('local', 'testing')) {
            $this->components->error('Este comando só pode ser executado nos ambientes local ou de testes isolados.');

            return self::FAILURE;
        }

        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== $expectedDatabase) {
            $this->components->error("Este comando exige a conexão MySQL do banco {$expectedDatabase} neste ambiente.");

            return self::FAILURE;
        }

        try {
            $actualDatabase = DB::connection('mysql')->selectOne('SELECT DATABASE() AS database_name')->database_name;
        } catch (\Throwable) {
            $this->components->error('Não foi possível validar o banco MySQL conectado; nenhuma conta foi alterada.');

            return self::FAILURE;
        }

        if ($actualDatabase !== $expectedDatabase) {
            $this->components->error("Banco conectado inesperado; era necessário {$expectedDatabase}. Nenhuma conta foi alterada.");

            return self::FAILURE;
        }

        $campus = $this->resolveCampus();
        if (! $campus) {
            return self::FAILURE;
        }

        $missingRoles = collect(self::DEMO_USERS)->pluck('role')->unique()
            ->reject(fn (string $name): bool => Role::query()->where('name', $name)->where('guard_name', 'web')->exists());
        if ($missingRoles->isNotEmpty()) {
            $this->components->error('As funções do CampusFix não estão preparadas. Execute os seeders de referência antes deste comando.');

            return self::FAILURE;
        }

        $existingUsers = User::query()->whereIn('email', array_keys(self::DEMO_USERS))->get()->keyBy('email');
        foreach (self::DEMO_USERS as $email => $profile) {
            $existing = $existingUsers->get($email);
            if ($existing && (! $existing->is_active || $existing->user_type !== $profile['user_type'] || ! $existing->hasRole($profile['role']))) {
                $this->components->error("A conta reservada {$email} existe com perfil incompatível ou inativo; nenhuma conta foi alterada.");

                return self::FAILURE;
            }
        }

        $existingTeams = Team::query()->where('name', 'Equipe de Manutenção Geral')->get();
        if ($existingTeams->count() > 1 || ($existingTeams->isNotEmpty() && (
            (int) $existingTeams->first()->campus_id !== (int) $campus->id || ! $existingTeams->first()->is_active
        ))) {
            $this->components->error('A equipe com esse nome já existe fora do campus selecionado, está inativa ou duplicada; nenhuma equipe foi alterada.');

            return self::FAILURE;
        }

        $passwords = [];
        foreach (self::DEMO_USERS as $email => $profile) {
            if ($existingUsers->has($email)) {
                continue;
            }

            $password = promptPassword("Senha para {$email} (mínimo 12 caracteres, maiúsculas, minúsculas, números e símbolos)");
            $confirmation = promptPassword("Confirme a senha para {$email}");
            $validator = Validator::make(['password' => $password], [
                'password' => ['required', Password::min(12)->letters()->mixedCase()->numbers()->symbols()],
            ]);

            if ($validator->fails()) {
                $this->components->error("A senha informada para {$email} não atende aos requisitos de segurança.");

                return self::FAILURE;
            }

            if (! hash_equals($password, $confirmation)) {
                $this->components->error("A confirmação da senha para {$email} não corresponde.");

                return self::FAILURE;
            }

            $passwords[$email] = $password;
        }

        $team = DB::transaction(function () use ($campus, $existingUsers, $passwords): Team {
            Campus::query()->whereKey($campus->id)->lockForUpdate()->firstOrFail();
            $users = [];

            foreach (self::DEMO_USERS as $email => $profile) {
                $user = $existingUsers->get($email);
                if (! $user) {
                    $user = User::create([
                        'name' => $profile['name'],
                        'email' => $email,
                        'user_type' => $profile['user_type'],
                        'is_active' => true,
                        'email_verified_at' => now(),
                        'password' => $passwords[$email],
                    ]);
                    $user->assignRole($profile['role']);
                }
                $users[$email] = $user;
            }

            $team = Team::query()->where('name', 'Equipe de Manutenção Geral')->first();
            if (! $team) {
                $team = Team::create([
                    'campus_id' => $campus->id,
                    'name' => 'Equipe de Manutenção Geral',
                    'description' => 'Equipe de demonstração para atendimento de solicitações de manutenção.',
                    'is_active' => true,
                ]);
                app(TeamAuditService::class)->record($team, $users['campusfix.demo.admin@example.test'], 'team_created', [], [
                    'name' => $team->name,
                    'campus_id' => $team->campus_id,
                    'is_active' => $team->is_active,
                ]);
            }

            $memberships = [
                'campusfix.demo.manager@example.test' => ['manager'],
                'campusfix.demo.technician1@example.test' => ['technician', 'lead'],
                'campusfix.demo.technician2@example.test' => ['technician', 'lead'],
            ];

            foreach ($memberships as $email => $validRoles) {
                $user = $users[$email];
                $membership = DB::table('team_members')->where('team_id', $team->id)->where('user_id', $user->id)->first();
                if ($membership && in_array($membership->member_role, $validRoles, true)) {
                    continue;
                }

                if ($membership && $membership->member_role !== 'former_technician') {
                    throw new \RuntimeException('A conta de demonstração possui um vínculo incompatível com a equipe.');
                }

                $memberRole = $validRoles[0];
                $joinedAt = now();
                if ($membership) {
                    DB::table('team_members')->where('team_id', $team->id)->where('user_id', $user->id)->update([
                        'member_role' => $memberRole,
                        'joined_at' => $joinedAt,
                        'updated_at' => $joinedAt,
                    ]);
                } else {
                    DB::table('team_members')->insert([
                        'team_id' => $team->id,
                        'user_id' => $user->id,
                        'member_role' => $memberRole,
                        'joined_at' => $joinedAt,
                        'created_at' => $joinedAt,
                        'updated_at' => $joinedAt,
                    ]);
                }

                app(TeamAuditService::class)->record(
                    $team,
                    $users['campusfix.demo.admin@example.test'],
                    $membership ? 'member_reactivated' : 'member_added',
                    $membership ? ['member_role' => $membership->member_role] : [],
                    ['member_role' => $memberRole, 'joined_at' => $joinedAt->toISOString()],
                    $user->id,
                    ['campus_id' => $team->campus_id],
                );
            }

            return $team;
        });

        $this->components->info("Perfis de demonstração prontos na equipe {$team->name} (campus {$campus->name}).");
        foreach (array_keys(self::DEMO_USERS) as $email) {
            $this->line($email);
        }
        $this->components->warn('As contas existentes e suas senhas foram preservadas; senhas novas foram solicitadas com entrada oculta.');

        return self::SUCCESS;
    }

    private function resolveCampus(): ?Campus
    {
        $campusOption = $this->option('campus');
        if ($campusOption !== null && $campusOption !== '') {
            $campus = Campus::query()->whereKey($campusOption)->where('is_active', true)->first();
            if (! $campus) {
                $this->components->error('O ID informado não corresponde a um campus ativo.');
            }

            return $campus;
        }

        $campuses = Campus::query()->where('is_active', true)->orderBy('id')->get();
        if ($campuses->count() !== 1) {
            $this->components->error($campuses->isEmpty()
                ? 'Não há campus ativo para associar à equipe.'
                : 'Há mais de um campus ativo. Informe um campus com --campus=ID.');

            return null;
        }

        return $campuses->first();
    }
}
