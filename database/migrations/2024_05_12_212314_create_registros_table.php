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
        Schema::create('registros', function (Blueprint $table) {
            $table->id();
            $table->string('descricao')->nullable(false);
            $table->date('data')->nullable(false);
            $table->float('qtd')->nullable(false);

            $table->unsignedBigInteger('id_alimento');

          
            $table->foreign('id_alimento')->references('id')->on('alimentos');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('registros');
    }
};