<?php

use App\Enums\TicketStatus;
use App\Models\Attachment;
use App\Models\Building;
use App\Models\Campus;
use App\Models\Category;
use App\Models\Location;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketWorkflow;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

it('issues a bearer token, exposes a safe user resource and revokes the current token', function () {
    $this->travelTo(now()->startOfSecond());
    $user = User::factory()->create(['email' => 'api@example.test', 'password' => 'CampusFixPass2026']);
    $user->assignRole('student');
    $legacyToken = $user->createToken('existing device')->plainTextToken;
    $legacyTokenRecord = PersonalAccessToken::query()->where('tokenable_id', $user->id)->firstOrFail();
    $legacyTokenRecord->forceFill(['created_at' => now()->subYear(), 'expires_at' => null])->save();

    $response = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'CampusFixPass2026', 'device_name' => 'Android test']);
    $response->assertOk()->assertJsonPath('data.token_type', 'Bearer')->assertJsonMissingPath('data.user.password');
    $token = $response->json('data.token');
    $issuedTokenRecord = PersonalAccessToken::query()->where('tokenable_id', $user->id)->where('name', 'Android test')->firstOrFail();
    expect($issuedTokenRecord->token)->not->toBe($token)
        ->and($issuedTokenRecord->expires_at->equalTo(now()->addDays(90)))->toBeTrue()
        ->and($user->tokens()->count())->toBe(2);

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.email', $user->email);
    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
    auth()->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    auth()->forgetGuards();
    $this->withToken($legacyToken)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.email', $user->email);
    expect($user->tokens()->count())->toBe(1)
        ->and($legacyTokenRecord->fresh()->expires_at)->toBeNull();
});

it('accepts a mobile token until its individual expiry and rejects it afterward', function () {
    $this->travelTo(now()->startOfSecond());
    $user = User::factory()->create(['email' => 'expiring-api@example.test', 'password' => 'CampusFixPass2026']);
    $response = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'CampusFixPass2026',
        'device_name' => 'Android expiry test',
    ])->assertOk();
    $token = $response->json('data.token');
    $expiresAt = PersonalAccessToken::query()->where('tokenable_id', $user->id)->firstOrFail()->expires_at;

    $this->travelTo($expiresAt->copy()->subSecond());
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();

    $this->travelTo($expiresAt->copy()->addSecond());
    auth()->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('rejects invalid credentials, inactive accounts and protected requests without tokens', function () {
    $inactive = User::factory()->create(['email' => 'disabled@example.test', 'password' => 'CampusFixPass2026', 'is_active' => false]);
    $this->postJson('/api/v1/auth/login', ['email' => 'bad@example.test', 'password' => 'wrong', 'device_name' => 'phone'])->assertUnprocessable();
    $this->postJson('/api/v1/auth/login', ['email' => $inactive->email, 'password' => 'CampusFixPass2026', 'device_name' => 'phone'])->assertForbidden();
    $this->getJson('/api/v1/auth/me')->assertUnauthorized();

    $active = User::factory()->create();
    $active->assignRole('student');
    $token = $active->createToken('test')->plainTextToken;
    $active->forceFill(['is_active' => false])->save();
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertForbidden();
    expect(PersonalAccessToken::query()->where('tokenable_id', $active->id)->exists())->toBeFalse();
});

it('limits ticket data to the requester and allows creation through the shared workflow', function () {
    $requester = User::factory()->create();
    $requester->assignRole('student');
    $other = User::factory()->create();
    $other->assignRole('student');
    $ownTicket = Ticket::factory()->create(['requester_id' => $requester->id]);
    $otherTicket = Ticket::factory()->create(['requester_id' => $other->id]);
    $token = $requester->createToken('api-test')->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/tickets')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ownTicket->id);
    $this->withToken($token)->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.stats.total', 1)->assertJsonPath('data.stats.near_due', 0);
    $this->withToken($token)->getJson('/api/v1/tickets/'.$otherTicket->id)->assertForbidden();

    $campus = Campus::factory()->create();
    $category = Category::factory()->create();
    $this->withToken($token)->postJson('/api/v1/tickets', ['title' => 'API repair', 'description' => 'Description', 'category_id' => $category->id, 'priority' => 'medium', 'campus_id' => $campus->id])
        ->assertCreated()->assertJsonPath('data.title', 'API repair')->assertJsonMissingPath('data.requester_id');

    $this->withToken($token)->patchJson('/api/v1/tickets/'.$ownTicket->id, ['title' => 'Updated title'])->assertOk();
    expect($ownTicket->fresh()->histories()->where('event_type', 'details_updated')->where('source', 'api')->exists())->toBeTrue();
});

it('protects internal comments and supports private attachments and rating rules', function () {
    Storage::fake('local');
    $requester = User::factory()->create();
    $requester->assignRole('student');
    $ticket = Ticket::factory()->create(['requester_id' => $requester->id, 'status' => TicketStatus::Resolved]);
    $token = $requester->createToken('api-test')->plainTextToken;

    $this->withToken($token)->postJson('/api/v1/tickets/'.$ticket->id.'/comments', ['body' => 'Internal', 'is_internal' => true])->assertForbidden();
    $this->withToken($token)->postJson('/api/v1/tickets/'.$ticket->id.'/comments', ['body' => 'Public update'])->assertCreated();
    $administrator = User::factory()->create();
    $administrator->assignRole('administrator');
    app(TicketWorkflow::class)->comment($ticket, $administrator, 'Private note', true);
    $this->withToken($token)->getJson('/api/v1/tickets/'.$ticket->id.'/comments')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonMissing(['body' => 'Private note']);
    $this->withToken($token)->post('/api/v1/tickets/'.$ticket->id.'/attachments', ['file' => UploadedFile::fake()->create('report.pdf', 10, 'application/pdf')], ['Authorization' => 'Bearer '.$token])
        ->assertCreated()->assertJsonMissingPath('data.path');
    $this->withToken($token)->getJson('/api/v1/tickets/'.$ticket->id.'/attachments')->assertOk()->assertJsonCount(1, 'data');
    $this->withToken($token)->postJson('/api/v1/tickets/'.$ticket->id.'/rating', ['score' => 5, 'comment' => 'Resolvido'])->assertCreated();
    $this->withToken($token)->postJson('/api/v1/tickets/'.$ticket->id.'/rating', ['score' => 4])->assertStatus(409);
});

it('creates a ticket, stores an image privately and lists it for its requester', function () {
    Storage::fake('local');
    $requester = User::factory()->create();
    $requester->assignRole('student');
    $token = $requester->createToken('image-attachment-flow')->plainTextToken;
    $campus = Campus::factory()->create();
    $category = Category::factory()->create();

    $created = $this->withToken($token)->postJson('/api/v1/tickets', [
        'title' => 'Imagem de teste',
        'description' => 'Chamado para validar o fluxo completo do anexo.',
        'category_id' => $category->id,
        'priority' => 'medium',
        'campus_id' => $campus->id,
    ])->assertCreated();
    $ticketId = $created->json('data.id');

    $uploaded = $this->withToken($token)->post('/api/v1/tickets/'.$ticketId.'/attachments', [
        'file' => UploadedFile::fake()->create('campus.jpg', 10, 'image/jpeg'),
    ]);
    $uploaded->assertCreated()->assertJsonPath('data.name', 'campus.jpg');
    $attachmentId = $uploaded->json('data.id');
    $attachment = Attachment::query()->findOrFail($attachmentId);

    expect($attachment->ticket_id)->toBe($ticketId)
        ->and($attachment->disk)->toBe('local');
    Storage::disk('local')->assertExists($attachment->path);

    $this->withToken($token)->getJson('/api/v1/tickets/'.$ticketId.'/attachments')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $attachmentId)
        ->assertJsonPath('data.0.name', 'campus.jpg')
        ->assertJsonMissingPath('data.0.path');
    $this->withToken($token)->get('/api/v1/attachments/'.$attachmentId)->assertOk();

    $otherRequester = User::factory()->create();
    $otherRequester->assignRole('student');
    $otherToken = $otherRequester->createToken('unauthorized-attachment-test')->plainTextToken;
    auth()->forgetGuards();
    $this->withToken($otherToken)->getJson('/api/v1/tickets/'.$ticketId.'/attachments')->assertForbidden();
    auth()->forgetGuards();
    $this->withToken($otherToken)->get('/api/v1/attachments/'.$attachmentId)->assertForbidden();
});

it('returns only active catalogs and filters campus buildings and building locations', function () {
    $student = User::factory()->create();
    $student->assignRole('student');
    $token = $student->createToken('catalog-test')->plainTextToken;
    $campus = Campus::factory()->create(['name' => 'Campus Norte']);
    Campus::factory()->create(['is_active' => false]);
    $category = Category::factory()->create();
    $inactiveCategory = Category::factory()->create(['is_active' => false]);
    $building = Building::factory()->for($campus)->create(['name' => 'Bloco A']);
    $otherBuilding = Building::factory()->create(['name' => 'Bloco B']);
    $location = Location::factory()->for($building)->create(['name' => 'Sala 1']);
    Location::factory()->for($otherBuilding)->create(['name' => 'Sala externa']);

    $response = $this->withToken($token)->getJson('/api/v1/catalogs?campus_id='.$campus->id)->assertOk();
    $categoryIds = collect($response->json('data.categories'))->pluck('id');

    expect($categoryIds)->toContain($category->id)
        ->and($categoryIds)->not->toContain($inactiveCategory->id);

    $response->assertJsonPath('data.buildings.0.id', $building->id)
        ->assertJsonPath('data.locations.0.id', $location->id);
});

it('validates status transitions through the shared workflow and marks API audit context', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('administrator');
    $ticket = Ticket::factory()->create();
    $token = $administrator->createToken('workflow-test')->plainTextToken;

    $this->withToken($token)->postJson('/api/v1/tickets/'.$ticket->id.'/status', ['status' => 'in_progress'])->assertUnprocessable();
    $this->withToken($token)->postJson('/api/v1/tickets/'.$ticket->id.'/status', ['status' => 'in_triage'])->assertOk();
    expect($ticket->fresh()->histories()->where('event_type', 'status_changed')->where('source', 'api')->exists())->toBeTrue();
});

it('returns only active campus-compatible assignment options within the ticket policy scope', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('administrator');
    $adminToken = $administrator->createToken('assignment-options')->plainTextToken;
    $campus = Campus::factory()->create();
    $otherCampus = Campus::factory()->create();
    $ticket = Ticket::factory()->create(['campus_id' => $campus->id]);
    $activeTeam = \App\Models\Team::factory()->for($campus)->create();
    $globalTeam = \App\Models\Team::factory()->create(['campus_id' => null]);
    \App\Models\Team::factory()->for($campus)->create(['is_active' => false]);
    $wrongCampusTeam = \App\Models\Team::factory()->for($otherCampus)->create();
    $technician = User::factory()->type(\App\Enums\UserType::Technician)->create();
    $technician->assignRole('technician');
    $inactiveTechnician = User::factory()->type(\App\Enums\UserType::Technician)->create(['is_active' => false]);
    $inactiveTechnician->assignRole('technician');
    $activeTeam->members()->attach($technician->id, ['member_role' => 'technician', 'joined_at' => now()]);
    $activeTeam->members()->attach($inactiveTechnician->id, ['member_role' => 'technician', 'joined_at' => now()]);

    $options = $this->withToken($adminToken)->getJson('/api/v1/tickets/'.$ticket->id.'/assignment-options')->assertOk()
        ->assertJsonPath('data.ticket_id', $ticket->id)->assertJsonFragment(['id' => $activeTeam->id, 'name' => $activeTeam->name])
        ->assertJsonMissing(['id' => $inactiveTechnician->id, 'name' => $inactiveTechnician->name]);
    $teamOptions = collect($options->json('data.teams'));
    expect($teamOptions->pluck('id')->all())->toContain($activeTeam->id, $globalTeam->id)->not->toContain($wrongCampusTeam->id);
    $activeTechnicians = collect($teamOptions->firstWhere('id', $activeTeam->id)['technicians']);
    expect($activeTechnicians->pluck('id')->all())->toContain($technician->id)->not->toContain($inactiveTechnician->id);

    $manager = User::factory()->type(\App\Enums\UserType::Manager)->create();
    $manager->assignRole('manager');
    $activeTeam->members()->attach($manager->id, ['member_role' => 'manager', 'joined_at' => now()]);
    $managerToken = $manager->createToken('manager-options')->plainTextToken;
    auth()->forgetGuards();
    $this->withToken($managerToken)->getJson('/api/v1/tickets/'.$ticket->id.'/assignment-options')->assertOk();
    $outOfScope = Ticket::factory()->create(['campus_id' => $otherCampus->id]);
    auth()->forgetGuards();
    $this->withToken($managerToken)->getJson('/api/v1/tickets/'.$outOfScope->id.'/assignment-options')->assertForbidden();
});

it('keeps notifications and reports within their permission and owner scope', function () {
    $student = User::factory()->create();
    $student->assignRole('student');
    $token = $student->createToken('api-test')->plainTextToken;
    $this->withToken($token)->getJson('/api/v1/reports')->assertForbidden();
    $this->withToken($token)->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('meta.total', 0);

    $administrator = User::factory()->create();
    $administrator->assignRole('administrator');
    $adminToken = $administrator->createToken('report-test')->plainTextToken;
    auth()->forgetGuards();
    $this->withToken($adminToken)->getJson('/api/v1/reports')->assertOk()->assertJsonStructure(['data' => ['summary' => ['near_due', 'overdue'], 'sla' => ['average_resolution_minutes', 'average_first_response_minutes'], 'by_month', 'by_team', 'by_technician']]);
});
