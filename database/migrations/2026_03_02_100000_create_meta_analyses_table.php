<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('url')->index();

            $table->text('original_title')->nullable();
            $table->text('original_description')->nullable();

            $table->text('suggested_title');
            $table->text('suggested_description');

            $table->json('keywords');
            $table->string('intent')->nullable();

            $table->unsignedInteger('word_count');

            $table->timestamp('analyzed_at');

            $table->timestamps();

            $table->index(['user_id', 'url']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_analyses');
    }
};
