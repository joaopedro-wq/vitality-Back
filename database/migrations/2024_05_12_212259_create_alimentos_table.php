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
        Schema::create('alimentos', function (Blueprint $table) {
            $table->id();
            $table->string('descricao')->nullable(false);
            $table->float('proteina')->nullable(false);
            $table->float('gordura')->nullable(false); 
            $table->float('caloria')->nullable(false); 
            $table->float('carbo')->nullable(false);
            $table->float('qtd')->nullable(false);
            $table->unsignedBigInteger('id_usuario')->nullable();

            $table->foreign('id_usuario')->references('id')->on('users');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alimentos');
    }
};
