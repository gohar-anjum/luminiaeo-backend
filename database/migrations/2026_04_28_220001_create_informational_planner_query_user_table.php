<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('informational_planner_query_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('informational_planner_query_id')
                ->constrained('informational_planner_queries')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'informational_planner_query_id'], 'planner_query_user_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('informational_planner_query_user');
    }
};
