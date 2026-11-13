<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campuses', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('code', 40)->unique();
            $table->string('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->char('state', 2)->nullable();
            $table->string('timezone', 64)->default('America/Manaus');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('buildings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campus_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('code', 40);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->unique(['campus_id', 'code']);
            $table->unique(['id', 'campus_id']);
        });

        Schema::create('locations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('building_id')->constrained()->restrictOnDelete();
            $table->string('name', 160);
            $table->string('floor', 40)->nullable();
            $table->string('room', 40)->nullable();
            $table->text('details')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->index(['building_id', 'floor', 'room']);
            $table->unique(['id', 'building_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
        Schema::dropIfExists('buildings');
        Schema::dropIfExists('campuses');
    }
};
