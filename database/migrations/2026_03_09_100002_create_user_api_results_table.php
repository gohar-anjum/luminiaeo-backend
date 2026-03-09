<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_api_results', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('api_result_id')
                ->constrained('api_results')
                ->cascadeOnDelete();

            $table->string('feature_key', 80)->index();
            $table->boolean('was_cache_hit')->default(false);
            $table->boolean('credit_charged')->default(false);
            $table->unsignedBigInteger('credit_transaction_id')->nullable();
            $table->timestamp('accessed_at');

            $table->timestamps();

            $table->unique(['user_id', 'api_result_id', 'feature_key'], 'user_result_feature_unique');
            $table->index(['user_id', 'feature_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_api_results');
    }
};
