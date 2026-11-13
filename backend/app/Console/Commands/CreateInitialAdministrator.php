<?php

namespace App\Console\Commands;

use App\Enums\UserType;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

use function Laravel\Prompts\password as promptPassword;
use function Laravel\Prompts\text;

class CreateInitialAdministrator extends Command
{
    protected $signature = 'campusfix:admin:create';

    protected $description = 'Cria o primeiro administrador por prompts interativos seguros';

    public function handle(): int
    {
        if (User::role('administrator')->exists()) {
            $this->components->error('Já existe um administrador. Use a gestão de funções autenticada.');

            return self::FAILURE;
        }

        if (! Role::query()->where('name', 'administrator')->where('guard_name', 'web')->exists()) {
            $this->components->error('Funções não encontradas. Execute os seeders antes de criar o administrador.');

            return self::FAILURE;
        }

        $name = trim(text('Nome do administrador'));
        $email = mb_strtolower(trim(text('E-mail do administrador')));
        $password = promptPassword('Senha (mínimo 12 caracteres, letras, números e símbolo)');
        $passwordConfirmation = promptPassword('Confirme a senha');

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:120'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', Password::min(12)->letters()->mixedCase()->numbers()->symbols()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        if (! hash_equals($password, $passwordConfirmation)) {
            $this->components->error('As senhas não coincidem.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($name, $email, $password): void {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'user_type' => UserType::Administrator,
                'is_active' => true,
                'password' => $password,
            ]);

            $user->forceFill(['email_verified_at' => now()])->save();
            $user->assignRole('administrator');
        });

        $this->components->info("Administrador {$email} criado.");

        return self::SUCCESS;
    }
}
