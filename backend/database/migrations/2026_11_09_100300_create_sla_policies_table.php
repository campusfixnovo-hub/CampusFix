<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sla_policies', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('priority', 16);
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('business_calendar_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('target_minutes');
            $table->unsignedInteger('warn_before_minutes')->default(60);
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['priority', 'category_id', 'is_active']);
            $table->index(['effective_from', 'effective_until']);
        });

        DB::statement("ALTER TABLE sla_policies ADD CONSTRAINT sla_policies_priority_valid CHECK (priority IN ('low', 'medium', 'high', 'critical'))");
        DB::statement('ALTER TABLE sla_policies ADD CONSTRAINT sla_policies_target_positive CHECK (target_minutes > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('sla_policies');
    }
};
