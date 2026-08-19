<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;


return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('user_badges');

        Schema::create('user_mission_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('mission_code', 60);
            // Data (diária), segunda-feira da semana (semanal) ou 'once' (marco, cumulativo,
            // nunca reseta) — mesma coluna serve os 3 escopos porque o que muda entre eles é só
            // a granularidade da chave, não a natureza do registro.
            $table->string('period_key', 20);
            $table->unsignedInteger('xp');
            $table->timestampTz('completed_at');
            $table->timestamps();
            $table->unique(['user_id', 'mission_code', 'period_key'], 'user_mission_completions_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_mission_completions');

        Schema::create('user_badges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('badge_code', 60);
            $table->timestampTz('unlocked_at');
            $table->timestamps();
            $table->unique(['user_id', 'badge_code'], 'user_badges_user_badge_unique');
        });
    }
};
