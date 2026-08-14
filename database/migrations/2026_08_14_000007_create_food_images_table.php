<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('food_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alimento_id')->constrained('alimentos')->cascadeOnDelete();
            $table->string('wikidata_id', 32)->nullable();
            $table->string('commons_filename')->nullable();
            $table->text('source_url')->nullable();
            $table->string('source_license', 80)->nullable();
            $table->text('source_license_url')->nullable();
            $table->text('source_author')->nullable();
            $table->string('path')->nullable();
            $table->string('image_hash', 64)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->decimal('match_score', 5, 2)->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('rejection_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['alimento_id', 'commons_filename'], 'food_images_food_commons_unique');
            $table->index(['status', 'match_score'], 'food_images_status_score_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('food_images');
    }
};
