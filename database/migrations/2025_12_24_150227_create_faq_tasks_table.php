<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faq_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('task_id')->unique()->index();
            $table->string('url')->nullable();
            $table->string('topic')->nullable();
            $table->string('alsoasked_search_id')->nullable()->index();
            $table->json('serp_questions')->nullable();
            $table->json('alsoasked_questions')->nullable();
            $table->json('options')->nullable();
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending')->index();
            $table->string('error_message')->nullable();
            $table->foreignId('faq_id')->nullable()->constrained('faqs')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faq_tasks');
    }
};
