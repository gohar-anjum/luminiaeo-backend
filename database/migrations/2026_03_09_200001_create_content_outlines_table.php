<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_outlines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('keyword');
            $table->string('tone')->default('professional');
            $table->json('outline');
            $table->json('semantic_keywords')->nullable();
            $table->string('intent')->nullable();
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->index(['user_id', 'keyword']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_outlines');
    }
};
