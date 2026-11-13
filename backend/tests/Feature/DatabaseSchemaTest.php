<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('uses the dedicated campusfix_test MySQL database', function () {
    $connection = DB::selectOne('SELECT DATABASE() AS database_name, @@character_set_database AS charset, @@collation_database AS collation');

    expect($connection->database_name)->toBe('campusfix_test')
        ->and($connection->charset)->toBe('utf8mb4')
        ->and($connection->collation)->toBe('utf8mb4_unicode_ci');
});

it('contains the CampusFix domain, SLA, audit, and access tables', function () {
    foreach ([
        'users', 'campuses', 'buildings', 'locations', 'categories', 'teams', 'team_members',
        'business_calendars', 'business_hours', 'business_holidays', 'sla_policies', 'tickets',
        'ticket_sla_pauses', 'ticket_histories', 'comments', 'attachments', 'ratings',
        'notifications', 'personal_access_tokens', 'roles', 'permissions', 'model_has_roles',
        'model_has_permissions', 'role_has_permissions',
    ] as $table) {
        expect(Schema::hasTable($table))->toBeTrue("Expected table [{$table}] to exist.");
    }
});

it('enforces the requester pause and SLA snapshot columns', function () {
    expect(Schema::hasColumns('ticket_sla_pauses', ['started_at', 'ended_at', 'started_by', 'ended_by', 'duration_minutes']))->toBeTrue()
        ->and(Schema::hasColumns('tickets', ['sla_policy_id', 'sla_target_minutes', 'due_at', 'sla_breached_at']))->toBeTrue();
});

it('has database constraints for ticket enums and cross-table integrity', function () {
    $checks = collect(DB::select('SELECT CONSTRAINT_NAME FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE()'))
        ->pluck('CONSTRAINT_NAME')->all();

    $foreignTables = collect(DB::select("SELECT DISTINCT REFERENCED_TABLE_NAME AS table_name FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tickets' AND REFERENCED_TABLE_NAME IS NOT NULL"))
        ->pluck('table_name')->all();

    expect($checks)->toContain('tickets_priority_valid', 'tickets_status_valid', 'sla_policies_target_positive')
        ->and($foreignTables)->toContain('buildings', 'locations', 'team_members', 'users', 'campuses', 'categories');
});

it('seeds the agreed starter SLA and reference catalogs', function () {
    expect(DB::table('sla_policies')->whereNull('category_id')->count())->toBe(4)
        ->and(DB::table('categories')->count())->toBe(10)
        ->and(DB::table('campuses')->count())->toBe(1);
});
