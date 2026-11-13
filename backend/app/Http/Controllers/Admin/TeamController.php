<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\{Campus, Team, User};
use App\Services\TeamAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TeamController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Team::class);
        $teams = Team::query()->with('campus')->withCount('members', 'tickets')
            ->when($request->user()->hasRole('manager'), fn ($q) => $q->whereHas('members', fn ($members) => $members
                ->whereKey($request->user()->id)->where('team_members.member_role', 'manager')))
            ->orderBy('name')->paginate(20);

        return view($request->user()->hasRole('manager') ? 'manager.teams.index' : 'admin.teams.index', compact('teams'));
    }

    public function create()
    {
        $this->authorize('create', Team::class);
        return view('admin.teams.form', $this->formData(new Team, 'admin.teams'));
    }

    public function store(Request $request)
    {
        $this->authorize('create', Team::class);
        $data = $this->validated($request);
        $technicianIds = $this->validatedTechnicianIds($data);
        $leadId = $this->validatedLeadId($data, $technicianIds);

        $team = DB::transaction(function () use ($request, $data, $technicianIds, $leadId) {
            $team = Team::create([
                'campus_id' => $data['campus_id'] ?? null,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_active' => $request->boolean('is_active', true),
            ]);
            $this->syncTechnicianMemberships($team, $technicianIds, $leadId);
            $audit = app(TeamAuditService::class);
            $audit->record($team, $request->user(), 'team_created', [], $this->teamSnapshot($team));
            $this->recordMembershipChanges($request->user(), $team, [], $this->membershipSnapshot($team));
            return $team;
        });
        return redirect()->route('admin.teams.edit', $team)->with('status', 'Equipe criada.');
    }

    public function edit(Request $request, Team $team)
    {
        $this->authorize('update', $team);
        $team->load(['technicians', 'leader']);
        $routePrefix = $request->user()->hasRole('manager') ? 'manager.teams' : 'admin.teams';
        return view('admin.teams.form', $this->formData($team, $routePrefix));
    }

    public function update(Request $request, Team $team)
    {
        $this->authorize('update', $team);
        $data = $this->validated($request);
        $technicianIds = $this->validatedTechnicianIds($data);
        $leadId = $this->validatedLeadId($data, $technicianIds);
        if ((int) ($data['campus_id'] ?? 0) !== (int) ($team->campus_id ?? 0) && $team->tickets()->exists()) {
            throw ValidationException::withMessages(['campus_id' => 'O campus não pode ser alterado depois que a equipe possui chamados vinculados.']);
        }
        if ($request->user()->hasRole('manager') && empty($data['campus_id'])) {
            throw ValidationException::withMessages(['campus_id' => 'Gestores precisam manter a equipe vinculada a um campus do próprio escopo.']);
        }
        if ($request->user()->hasRole('manager') && ! $request->user()->teams()
            ->where('team_members.member_role', 'manager')->where('campus_id', $data['campus_id'])->exists()) {
            throw ValidationException::withMessages(['campus_id' => 'O campus selecionado está fora do seu escopo.']);
        }

        DB::transaction(function () use ($request, $team, $data, $technicianIds, $leadId) {
            $team->refresh();
            $oldTeam = $this->teamSnapshot($team);
            $oldMembers = $this->membershipSnapshot($team);
            $team->update([
                'campus_id' => $data['campus_id'] ?? null,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_active' => $request->boolean('is_active'),
            ]);
            $this->syncTechnicianMemberships($team, $technicianIds, $leadId);
            $newMembers = $this->membershipSnapshot($team);
            if ($oldMembers !== $newMembers) $this->recordMembershipChanges($request->user(), $team, $oldMembers, $newMembers);
            $newTeam = $this->teamSnapshot($team->fresh());
            if ($oldTeam !== $newTeam) $this->logTeamChange($request->user(), $team, 'team_updated', $oldTeam, $newTeam);
        });

        return back()->with('status', 'Equipe e composição atualizadas.');
    }

    public function destroy(Request $request, Team $team)
    {
        $this->authorize('deactivate', $team);
        DB::transaction(function () use ($request, $team): void {
            $before = $this->teamSnapshot($team->fresh());
            $team->forceFill(['is_active' => false])->save();
            $this->logTeamChange($request->user(), $team, 'team_deactivated', $before, $this->teamSnapshot($team->fresh()));
        });
        return back()->with('status', 'Equipe desativada. Os vínculos e chamados históricos foram preservados.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'campus_id' => ['nullable', Rule::exists('campuses', 'id')->where('is_active', true)],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
            'technicians' => ['nullable', 'array'],
            'technicians.*' => ['integer', 'distinct'],
            'lead_user_id' => ['nullable', 'integer'],
        ]);
    }

    private function validatedTechnicianIds(array $data): array
    {
        $ids = collect($data['technicians'] ?? [])->map(fn ($id) => (int) $id)->unique()->values()->all();
        $validCount = User::role('technician')->where('is_active', true)->whereIn('id', $ids)->count();
        if ($validCount !== count($ids)) {
            throw ValidationException::withMessages(['technicians' => 'Todos os membros precisam ser técnicos ativos.']);
        }
        return $ids;
    }

    private function validatedLeadId(array $data, array $technicianIds): ?int
    {
        $leadId = isset($data['lead_user_id']) ? (int) $data['lead_user_id'] : null;
        if ($leadId !== null && ! in_array($leadId, $technicianIds, true)) {
            throw ValidationException::withMessages(['lead_user_id' => 'O responsável precisa ser um técnico ativo incluído na equipe.']);
        }
        if ($technicianIds !== [] && $leadId === null) {
            throw ValidationException::withMessages(['lead_user_id' => 'Selecione o responsável pela equipe.']);
        }
        return $leadId;
    }

    private function syncTechnicianMemberships(Team $team, array $technicianIds, ?int $leadId): void
    {
        $now = now();
        $memberships = DB::table('team_members')->where('team_id', $team->id)->get()->keyBy('user_id');
        foreach ($memberships as $userId => $membership) {
            if (! in_array($membership->member_role, ['technician', 'lead'], true)) continue;
            if (! in_array((int) $userId, $technicianIds, true)) {
                DB::table('team_members')->where('team_id', $team->id)->where('user_id', $userId)->update([
                    'member_role' => 'former_technician', 'updated_at' => $now,
                ]);
            }
        }

        foreach ($technicianIds as $userId) {
            $role = $userId === $leadId ? 'lead' : 'technician';
            $existing = $memberships->get($userId);
            if ($existing) {
                $wasActive = in_array($existing->member_role, ['technician', 'lead'], true);
                DB::table('team_members')->where('team_id', $team->id)->where('user_id', $userId)->update([
                    'member_role' => $role,
                    'joined_at' => $wasActive ? $existing->joined_at : $now,
                    'updated_at' => $now,
                ]);
            } else {
                DB::table('team_members')->insert([
                    'team_id' => $team->id, 'user_id' => $userId, 'member_role' => $role,
                    'joined_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    private function formData(Team $team, string $routePrefix): array
    {
        return [
            'team' => $team,
            'routePrefix' => $routePrefix,
            'campuses' => Campus::where('is_active', true)->orderBy('name')->get(),
            'technicians' => User::role('technician')->where('is_active', true)->orderBy('name')->get(),
            'selectedTechnicianIds' => $team->exists ? $team->technicians()->pluck('users.id')->all() : [],
            'leadId' => $team->exists ? $team->leader()->value('users.id') : null,
        ];
    }

    private function teamSnapshot(Team $team): array
    {
        return ['name'=>$team->name,'campus_id'=>$team->campus_id,'is_active'=>$team->is_active];
    }

    private function membershipSnapshot(Team $team): array
    {
        return DB::table('team_members')->where('team_id',$team->id)
            ->orderBy('user_id')->get(['user_id','member_role','joined_at'])
            ->map(fn ($row) => ['user_id'=>(int)$row->user_id,'member_role'=>$row->member_role,'joined_at'=>$row->joined_at])->all();
    }

    private function logTeamChange(User $actor, Team $team, string $event, array $old, array $new): void
    {
        app(\App\Services\TeamAuditService::class)->record($team, $actor, $event, $old, $new, null, ['campus_id'=>$team->campus_id]);
    }

    private function recordMembershipChanges(User $actor, Team $team, array $before, array $after): void
    {
        $oldByUser = collect($before)->keyBy('user_id');
        $newByUser = collect($after)->keyBy('user_id');
        $ids = $oldByUser->keys()->merge($newByUser->keys())->unique();
        $active = ['technician','lead','manager'];
        foreach ($ids as $id) {
            $old = $oldByUser->get($id);
            $new = $newByUser->get($id);
            $oldRole = $old['member_role'] ?? null;
            $newRole = $new['member_role'] ?? null;
            if ($oldRole === $newRole && ($old['joined_at'] ?? null) === ($new['joined_at'] ?? null)) continue;

            $event = ! in_array($oldRole, $active, true) && in_array($newRole, $active, true)
                ? ($oldRole === null ? 'member_added' : 'member_reactivated')
                : (in_array($oldRole, $active, true) && ! in_array($newRole, $active, true)
                    ? 'member_removed'
                    : 'team_lead_changed');

            app(TeamAuditService::class)->record(
                $team, $actor, $event,
                $old ? ['member_role'=>$oldRole,'joined_at'=>$old['joined_at']] : [],
                $new ? ['member_role'=>$newRole,'joined_at'=>$new['joined_at']] : [],
                (int) $id,
            );
        }
    }
}
