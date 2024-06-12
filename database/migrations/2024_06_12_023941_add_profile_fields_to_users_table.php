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
            $table->date('data_nascimento')->nullable();
            $table->char('genero', 1)->nullable(); 
            $table->float('peso')->nullable();
            
            $table->integer('altura')->nullable();
            $table->string('nivel_atividade')->nullable();
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
            

        });
    }
};
