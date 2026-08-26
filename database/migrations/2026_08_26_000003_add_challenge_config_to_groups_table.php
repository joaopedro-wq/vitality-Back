<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->string('challenge_type', 20)->default('weekly')->after('invite_code');
            $table->timestampTz('challenge_starts_at')->nullable()->after('challenge_type');
            $table->timestampTz('challenge_ends_at')->nullable()->after('challenge_starts_at');
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropColumn(['challenge_type', 'challenge_starts_at', 'challenge_ends_at']);
        });
    }
};
