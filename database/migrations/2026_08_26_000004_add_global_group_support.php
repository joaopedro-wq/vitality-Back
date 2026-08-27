<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // `->change()` (via doctrine/dbal) em vez de `DB::statement('ALTER TABLE ... ALTER COLUMN')`
        // — a sintaxe antiga era exclusiva do Postgres e quebrava a suíte de testes inteira, que
        // roda em SQLite (CLAUDE.md, "Catálogo e testes"): SQLite não entende `ALTER COLUMN`.
        // `->change()` gera o SQL correto por driver (inclusive o dança de recriar tabela que o
        // SQLite exige por baixo dos panos).
        Schema::table('groups', function (Blueprint $table) {
            $table->dropForeign(['owner_id']);
        });

        Schema::table('groups', function (Blueprint $table) {
            $table->foreignId('owner_id')->nullable()->change();
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
            $table->foreignId('owner_id')->nullable(false)->change();
        });

        Schema::table('groups', function (Blueprint $table) {
            $table->foreign('owner_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
