<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateInstitutionalUserRequest;
use App\Http\Requests\UpdateUserRoleRequest;
use App\Models\User;
use App\Models\Team;
use App\Services\TeamAuditService;
use Illuminate\Support\Facades\DB;

class UserRoleController extends Controller
{
    public function index()
    {
        $users = User::with('roles')->orderBy('name')->paginate(20);
        $roles = \Spatie\Permission\Models\Role::where('guard_name', 'web')->orderBy('name')->get();
        return view('admin.users.index', compact('users', 'roles'));
    }

    public function store(CreateInstitutionalUserRequest $request)
    {
        $data = $request->validated();
        $role = UserType::from($data['role']);

        DB::transaction(function () use ($data, $role): void {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'user_type' => $role,
                'is_active' => true,
                'password' => $data['password'],
            ]);

            $user->syncRoles([$role->value]);
        });

        return back()->with('status', 'Conta institucional criada.');
    }

    public function update(UpdateUserRoleRequest $request, User $user)
    {
        $role = UserType::from($request->validated('role'));

        DB::transaction(function () use ($request, $user, $role): void {
            $oldRole = $user->user_type?->value;
            if ($oldRole === 'technician' && $role->value !== 'technician') {
                $this->archiveTechnicianMemberships($request->user(), $user, 'technician_role_removed');
            }
            $user->forceFill(['user_type' => $role])->save();
            $user->syncRoles([$role->value]);
        });

        return back()->with('status', 'Função do usuário atualizada.');
    }

    public function toggle(\Illuminate\Http\Request $request, User $user)
    {
        $this->authorize('toggleActive', $user);
        $request->validate(['is_active' => ['required', 'boolean']]);
        DB::transaction(function () use ($request, $user): void {
            $active = $request->boolean('is_active');
            if (! $active && $user->is_active && $user->hasRole('technician')) {
                $this->archiveTechnicianMemberships($request->user(), $user, 'technician_deactivated');
            }
            $user->forceFill(['is_active' => $active])->save();
        });
        return back()->with('status', $user->is_active ? 'Conta ativada.' : 'Conta desativada.');
    }

    private function archiveTechnicianMemberships(User $actor, User $technician, string $event): void
    {
        $memberships = DB::table('team_members')->where('user_id', $technician->id)
            ->whereIn('member_role', ['technician', 'lead'])->get();

        foreach ($memberships as $membership) {
            DB::table('team_members')->where('team_id', $membership->team_id)->where('user_id', $technician->id)
                ->update(['member_role' => 'former_technician', 'updated_at' => now()]);

            $team = Team::find($membership->team_id);
            if ($team) {
                app(TeamAuditService::class)->record(
                    $team, $actor, $event,
                    ['member_role' => $membership->member_role, 'joined_at' => $membership->joined_at],
                    ['member_role' => 'former_technician', 'joined_at' => $membership->joined_at],
                    $technician->id,
                );
            }
        }
    }
}
