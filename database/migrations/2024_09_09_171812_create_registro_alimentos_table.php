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
        Schema::create('registro_alimentos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('registro_id');
            $table->unsignedBigInteger('alimento_id');
            $table->float('qtd'); 

            $table->foreign('registro_id')->references('id')->on('registros')->onDelete('cascade');
            $table->foreign('alimento_id')->references('id')->on('alimentos')->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('registro_alimentos');
    }
};
