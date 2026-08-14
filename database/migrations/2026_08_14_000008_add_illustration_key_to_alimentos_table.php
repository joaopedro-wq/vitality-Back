<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alimentos', function (Blueprint $table) {
            $table->string('illustration_key', 40)->nullable()->after('grupo');
            $table->index('illustration_key', 'alimentos_illustration_key_index');
        });
    }

    public function down(): void
    {
        Schema::table('alimentos', function (Blueprint $table) {
            $table->dropIndex('alimentos_illustration_key_index');
            $table->dropColumn('illustration_key');
        });
    }
};
