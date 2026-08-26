<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE groups ALTER COLUMN owner_id DROP NOT NULL');

        Schema::table('groups', function (Blueprint $table) {
            $table->dropForeign(['owner_id']);
        });

        Schema::table('groups', function (Blueprint $table) {
            $table->foreign('owner_id')->references('id')->on('users')->nullOnDelete();
            $table->boolean('is_global')->default(false)->after('challenge_ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropColumn('is_global');
            $table->dropForeign(['owner_id']);
        });

        Schema::table('groups', function (Blueprint $table) {
            $table->foreign('owner_id')->references('id')->on('users')->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE groups ALTER COLUMN owner_id SET NOT NULL');
    }
};
