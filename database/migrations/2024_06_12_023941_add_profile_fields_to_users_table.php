<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->date('data_nascimento')->nullable(true);
            $table->char('genero', 1)->nullable(true); 
            $table->float('peso')->nullable(true);
            $table->integer('altura')->nullable(true);
            $table->string('nivel_atividade')->nullable(true);
            $table->string('objetivo')->nullable(true);

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('data_nascimento');
            $table->dropColumn('genero');
            $table->dropColumn('peso');
            $table->dropColumn('altura');
            $table->dropColumn('nivel_atividade');
            $table->dropColumn('objetivo');

            

        });
    }
};
