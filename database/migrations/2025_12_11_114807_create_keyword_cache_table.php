<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('keyword_cache', function (Blueprint $table) {
            $table->id();
            $table->string('keyword', 255)->index();
            $table->string('language_code', 2)->default('en')->index();
            $table->integer('location_code')->default(2840)->index();
            $table->integer('search_volume')->nullable();
            $table->float('competition')->nullable();
            $table->float('cpc')->nullable();
            $table->integer('difficulty')->nullable();
            $table->json('serp_features')->nullable();
            $table->json('related_keywords')->nullable();
            $table->json('trends')->nullable();
            $table->string('cluster_id')->nullable()->index();
            $table->json('cluster_data')->nullable();
            $table->timestamp('cached_at')->useCurrent();
            $table->timestamp('expires_at')->index();
            $table->string('source', 50)->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['keyword', 'language_code', 'location_code'], 'keyword_cache_unique');

            $table->index(['expires_at', 'cached_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('keyword_cache');
    }
};
