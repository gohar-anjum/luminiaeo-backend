<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_queries', function (Blueprint $table) {
            $table->id();

            $table->string('api_provider', 50)->index();
            $table->string('feature', 80)->index();
            $table->char('query_hash', 64)->unique();
            $table->json('query_parameters');

            $table->timestamps();

            $table->index(['api_provider', 'feature']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_queries');
    }
};
