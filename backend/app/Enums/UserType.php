<?php

namespace App\Enums;

enum UserType: string
{
    case Student = 'student';
    case Professor = 'professor';
    case Staff = 'staff';
    case Technician = 'technician';
    case Manager = 'manager';
    case Administrator = 'administrator';

    public function label(): string
    {
        return match ($this) {
            self::Student => 'Estudante',
            self::Professor => 'Professor(a)',
            self::Staff => 'Funcionário(a)',
            self::Technician => 'Técnico(a)',
            self::Manager => 'Gestor(a)',
            self::Administrator => 'Administrador(a)',
        };
    }

}
