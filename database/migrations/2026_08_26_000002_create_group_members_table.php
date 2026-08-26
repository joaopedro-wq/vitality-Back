<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('joined_at');
            $table->timestamps();
            $table->unique(['group_id', 'user_id'], 'group_members_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_members');
    }
};
