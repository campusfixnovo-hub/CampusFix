<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 24)->nullable()->unique();
            $table->string('title', 180);
            $table->longText('description');
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('priority', 16)->default('medium');
            $table->string('status', 32)->default('open');
            $table->foreignId('requester_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('campus_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('building_id')->nullable();
            $table->unsignedBigInteger('location_id')->nullable();
            $table->text('location_details')->nullable();
            $table->foreignId('team_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('sla_policy_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('sla_target_minutes')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('sla_breached_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign(['building_id', 'campus_id'])
                ->references(['id', 'campus_id'])->on('buildings')->restrictOnDelete();
            $table->foreign(['location_id', 'building_id'])
                ->references(['id', 'building_id'])->on('locations')->restrictOnDelete();
            $table->foreign(['team_id', 'assigned_user_id'])
                ->references(['team_id', 'user_id'])->on('team_members')->restrictOnDelete();

            $table->index(['requester_id', 'created_at']);
            $table->index(['status', 'priority']);
            $table->index(['campus_id', 'status']);
            $table->index(['category_id', 'created_at']);
            $table->index(['team_id', 'status']);
            $table->index(['assigned_user_id', 'status']);
            $table->index(['status', 'due_at']);
        });

        DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_priority_valid CHECK (priority IN ('low', 'medium', 'high', 'critical'))");
        DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_status_valid CHECK (status IN ('open', 'in_triage', 'awaiting_service', 'in_progress', 'awaiting_material', 'awaiting_requester', 'resolved', 'closed', 'reopened', 'cancelled'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
