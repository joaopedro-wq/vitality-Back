<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alimentos', function (Blueprint $table) {
            $table->string('fonte', 20)->nullable()->after('id_usuario');
            $table->string('source_reference')->nullable()->after('fonte');
            $table->string('grupo')->nullable()->after('source_reference');
            $table->string('nome_normalizado')->nullable()->after('grupo');
            $table->string('status', 20)->default('ativo')->after('nome_normalizado');
            $table->foreignId('created_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->unique(['fonte', 'source_reference'], 'alimentos_fonte_referencia_unique');
            $table->index(['status', 'nome_normalizado'], 'alimentos_status_nome_index');
        });
    }

    public function down(): void
    {
        Schema::table('alimentos', function (Blueprint $table) {
            $table->dropIndex('alimentos_status_nome_index');
            $table->dropUnique('alimentos_fonte_referencia_unique');
            $table->dropConstrainedForeignId('updated_by');
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['fonte', 'source_reference', 'grupo', 'nome_normalizado', 'status']);
        });
    }
};
