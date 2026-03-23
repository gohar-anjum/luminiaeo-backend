<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_keyword_cluster_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('cache_key', 64);
            $table->timestamps();

            $table->unique(['user_id', 'cache_key']);
            $table->index('cache_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_keyword_cluster_access');
    }
};
