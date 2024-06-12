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
        Schema::create('meta_diarias', function (Blueprint $table) {
            $table->id();
            $table->date('data')->nullable(true);
            $table->float('meta_calorias')->nullable(false);
            $table->float('meta_proteinas')->nullable(false);
            $table->float('meta_carboidratos')->nullable(false);
            $table->float('meta_gorduras')->nullable(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meta_diarias');
    }
};
