<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('informational_keyword_planner_runs')) {
            Schema::table('informational_keyword_planner_runs', function (Blueprint $table) {
                $table->foreignId('informational_planner_query_id')
                    ->nullable()
                    ->constrained('informational_planner_queries')
                    ->nullOnDelete();
                $table->index('informational_planner_query_id');
            });
        }

        if (Schema::hasTable('keywords') && ! Schema::hasColumn('keywords', 'informational_planner_query_id')) {
            Schema::table('keywords', function (Blueprint $table) {
                $table->foreignId('informational_planner_query_id')
                    ->nullable()
                    ->constrained('informational_planner_queries')
                    ->cascadeOnDelete();
                $table->index('informational_planner_query_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('informational_keyword_planner_runs') && Schema::hasColumn('informational_keyword_planner_runs', 'informational_planner_query_id')) {
            Schema::table('informational_keyword_planner_runs', function (Blueprint $table) {
                $table->dropForeign(['informational_planner_query_id']);
            });
        }

        if (Schema::hasTable('keywords') && Schema::hasColumn('keywords', 'informational_planner_query_id')) {
            Schema::table('keywords', function (Blueprint $table) {
                $table->dropForeign(['informational_planner_query_id']);
            });
        }
    }
};
