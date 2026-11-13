<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            'tickets.create', 'tickets.view-own', 'tickets.cancel-own', 'tickets.view-assigned',
            'tickets.view-team', 'tickets.view-all', 'tickets.update-status', 'tickets.assign',
            'comments.create-public', 'comments.create-internal', 'attachments.upload', 'ratings.create',
            'teams.manage', 'reports.view-team', 'reports.view-all', 'catalog.manage', 'users.manage',
            'roles.manage', 'sla.manage',
        ];

        $records = collect($permissions)->mapWithKeys(fn (string $name) => [
            $name => Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']),
        ]);

        $requester = ['tickets.create', 'tickets.view-own', 'tickets.cancel-own', 'comments.create-public', 'attachments.upload', 'ratings.create'];

        foreach (['student', 'professor', 'staff'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web'])->syncPermissions($records->only($requester)->values());
        }

        Role::firstOrCreate(['name' => 'technician', 'guard_name' => 'web'])
            ->syncPermissions($records->only([...$requester, 'tickets.view-assigned', 'tickets.view-team', 'tickets.update-status', 'comments.create-internal'])->values());

        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web'])
            ->syncPermissions($records->only([...$requester, 'tickets.view-team', 'tickets.assign', 'teams.manage', 'reports.view-team', 'comments.create-internal'])->values());

        Role::firstOrCreate(['name' => 'administrator', 'guard_name' => 'web'])
            ->syncPermissions($records->values());

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
