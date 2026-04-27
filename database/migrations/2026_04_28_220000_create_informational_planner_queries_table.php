<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('informational_planner_queries', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint', 64)->unique();
            $table->json('seeds');
            $table->json('options');
            $table->json('keywords');
            $table->unsignedInteger('total_count')->default(0);
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('informational_planner_queries');
    }
};
