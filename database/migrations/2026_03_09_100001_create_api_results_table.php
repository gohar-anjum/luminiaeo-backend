<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_results', function (Blueprint $table) {
            $table->id();

            $table->foreignId('api_query_id')
                ->constrained('api_queries')
                ->cascadeOnDelete();

            $table->longText('response_payload');
            $table->json('response_meta')->nullable();
            $table->boolean('is_compressed')->default(false);
            $table->unsignedInteger('byte_size')->default(0);

            $table->timestamp('fetched_at');
            $table->timestamp('expires_at')->index();

            $table->timestamps();

            $table->index(['api_query_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_results');
    }
};
