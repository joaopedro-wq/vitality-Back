<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_badges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('badge_code', 60);
            $table->timestampTz('unlocked_at');
            $table->timestamps();
            $table->unique(['user_id', 'badge_code'], 'user_badges_user_badge_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_badges');
    }
};
