<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('keywords', function (Blueprint $table) {
            $table->id();
            $table->string('keyword');
            $table->integer('search_volume')->nullable();
            $table->float('competition')->nullable();
            $table->float('cpc')->nullable();
            $table->string('intent')->nullable();
            $table->string('location')->nullable();
            $table->timestamps();
        });
        Schema::create('keyword_research_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('query');
            $table->string('status')->default('pending');
            $table->json('result')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('keywords');
        Schema::dropIfExists('keyword_research_jobs');
    }
};
