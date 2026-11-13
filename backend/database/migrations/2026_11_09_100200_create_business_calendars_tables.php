<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_calendars', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('timezone', 64)->default('America/Manaus');
            $table->boolean('is_default')->default(false)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('business_hours', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_calendar_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday'); // ISO-8601: Monday=1 ... Sunday=7.
            $table->unsignedTinyInteger('segment');
            $table->time('opens_at');
            $table->time('closes_at');
            $table->timestamps();
            $table->unique(['business_calendar_id', 'weekday', 'segment']);
            $table->index(['business_calendar_id', 'weekday']);
        });

        Schema::create('business_holidays', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_calendar_id')->constrained()->cascadeOnDelete();
            $table->date('holiday_date');
            $table->string('name', 160);
            $table->boolean('is_working_day')->default(false);
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->timestamps();
            $table->unique(['business_calendar_id', 'holiday_date']);
        });

        DB::statement('ALTER TABLE business_hours ADD CONSTRAINT business_hours_weekday_valid CHECK (weekday BETWEEN 1 AND 7)');
        DB::statement('ALTER TABLE business_hours ADD CONSTRAINT business_hours_interval_valid CHECK (closes_at > opens_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('business_holidays');
        Schema::dropIfExists('business_hours');
        Schema::dropIfExists('business_calendars');
    }
};
