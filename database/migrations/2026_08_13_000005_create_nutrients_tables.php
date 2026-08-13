<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alimentos', function (Blueprint $table) {
            $table->string('source_version')->nullable()->after('source_reference');
            $table->string('source_checksum', 64)->nullable()->after('source_version');
        });

        Schema::create('nutrientes', function (Blueprint $table) {
            $table->id();
            $table->string('codigo')->unique();
            $table->string('nome');
            $table->string('unidade', 16);
            $table->string('categoria', 32)->default('outro');
            $table->timestamps();
        });

        Schema::create('alimento_nutrientes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alimento_id')->constrained('alimentos')->cascadeOnDelete();
            $table->foreignId('nutriente_id')->constrained('nutrientes')->cascadeOnDelete();
            $table->decimal('valor', 14, 5)->nullable();
            $table->string('tipo_dado', 32)->nullable();
            $table->timestamps();
            $table->unique(['alimento_id', 'nutriente_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alimento_nutrientes');
        Schema::dropIfExists('nutrientes');
        Schema::table('alimentos', fn (Blueprint $table) => $table->dropColumn(['source_version', 'source_checksum']));
    }
};
