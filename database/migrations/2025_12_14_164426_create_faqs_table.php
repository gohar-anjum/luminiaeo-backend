<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faqs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('url')->nullable()->index();
            $table->string('topic')->nullable()->index();
            $table->json('faqs');
            $table->json('options')->nullable();
            $table->string('source_hash')->unique()->index();
            $table->integer('api_calls_saved')->default(0);
            $table->timestamps();

            $table->index(['url', 'created_at']);
            $table->index(['topic', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faqs');
    }
};
