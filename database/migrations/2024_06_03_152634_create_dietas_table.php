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
        Schema::create('dietas', function (Blueprint $table) {
            $table->id();
            $table->string('descricao')->nullable(false);
            $table->unsignedBigInteger('id_usuario');
            $table->unsignedBigInteger('id_refeicao');
            
            
            
            $table->foreign('id_usuario')->references('id')->on('users');
            $table->foreign('id_refeicao')->references('id')->on('refeicaos');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dietas');
    }
};
