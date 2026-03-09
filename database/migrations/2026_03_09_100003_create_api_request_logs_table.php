<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_request_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->unsignedBigInteger('api_query_id')->nullable()->index();
            $table->unsignedBigInteger('api_result_id')->nullable()->index();

            $table->string('api_provider', 50)->index();
            $table->string('feature', 80)->index();
            $table->boolean('was_cache_hit')->default(false);
            $table->boolean('credit_charged')->default(false);

            $table->json('request_payload')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamp('created_at')->index();
            $table->timestamp('updated_at')->nullable();

            $table->index(['api_provider', 'feature', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_request_logs');
    }
};
