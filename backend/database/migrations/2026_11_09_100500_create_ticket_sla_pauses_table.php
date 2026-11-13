<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_sla_pauses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->restrictOnDelete();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('start_reason', 255);
            $table->string('end_reason', 255)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->timestamps();
            $table->index(['ticket_id', 'ended_at']);
            $table->index('started_at');
        });

        DB::statement('ALTER TABLE ticket_sla_pauses ADD CONSTRAINT ticket_sla_pause_times_valid CHECK (ended_at IS NULL OR ended_at >= started_at)');
        DB::statement('ALTER TABLE ticket_sla_pauses ADD CONSTRAINT ticket_sla_pause_duration_valid CHECK ((ended_at IS NULL AND duration_minutes IS NULL) OR (ended_at IS NOT NULL AND duration_minutes IS NOT NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_sla_pauses');
    }
};
