<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('keywords') || ! Schema::hasTable('informational_planner_queries')) {
            return;
        }

        if (Schema::hasColumn('keywords', 'informational_planner_query_id')) {
            return;
        }

        Schema::table('keywords', function (Blueprint $table) {
            $table->foreignId('informational_planner_query_id')
                ->nullable()
                ->after('id')
                ->constrained('informational_planner_queries')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('keywords') || ! Schema::hasColumn('keywords', 'informational_planner_query_id')) {
            return;
        }

        Schema::table('keywords', function (Blueprint $table) {
            $table->dropForeign(['informational_planner_query_id']);
            $table->dropColumn('informational_planner_query_id');
        });
    }
};
