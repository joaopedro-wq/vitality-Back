<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('food_catalog_versions', function (Blueprint $table) {
            $table->id();
            $table->string('source', 30);
            $table->string('version', 50);
            $table->string('checksum', 64);
            $table->string('status', 20)->default('staging')->index();
            $table->json('summary')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
            $table->unique(['source', 'version', 'checksum']);
        });

        Schema::table('alimentos', function (Blueprint $table) {
            $table->foreignId('catalog_version_id')->nullable()->after('source_checksum')
                ->constrained('food_catalog_versions')->nullOnDelete();
            $table->index(['catalog_version_id', 'status']);
        });

        Schema::create('food_planning_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alimento_id')->unique()->constrained('alimentos')->cascadeOnDelete();
            $table->string('family', 40)->index();
            $table->string('consumption_form', 30);
            $table->string('preparation', 40);
            $table->boolean('direct_consumption');
            $table->boolean('support_ingredient')->default(false);
            $table->decimal('portion_min_g', 7, 1);
            $table->decimal('portion_max_g', 7, 1);
            $table->decimal('portion_step_g', 7, 1);
            $table->json('diet_compatibility');
            $table->json('restriction_compatibility');
            $table->decimal('confidence', 4, 3)->default(1);
            $table->string('review_status', 20)->default('automatico')->index();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('food_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alimento_id')->constrained('alimentos')->cascadeOnDelete();
            $table->string('alias');
            $table->string('normalized')->index();
            $table->timestamps();
            $table->unique(['alimento_id', 'normalized']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('food_aliases');
        Schema::dropIfExists('food_planning_profiles');
        Schema::table('alimentos', function (Blueprint $table) {
            $table->dropIndex(['catalog_version_id', 'status']);
            $table->dropConstrainedForeignId('catalog_version_id');
        });
        Schema::dropIfExists('food_catalog_versions');
    }
};
