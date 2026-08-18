<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alimentos', function (Blueprint $table) {
            $table->string('grupo_normalizado', 40)->nullable()->after('grupo');
            $table->index('grupo_normalizado', 'alimentos_grupo_normalizado_index');
        });
    }

    public function down(): void
    {
        Schema::table('alimentos', function (Blueprint $table) {
            $table->dropIndex('alimentos_grupo_normalizado_index');
            $table->dropColumn('grupo_normalizado');
        });
    }
};
