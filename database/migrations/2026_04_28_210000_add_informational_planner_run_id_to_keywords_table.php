<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('keywords')) {
            return;
        }

        Schema::table('keywords', function (Blueprint $table) {
            $table->foreignId('informational_keyword_planner_run_id')
                ->nullable()
                ->constrained('informational_keyword_planner_runs')
                ->cascadeOnDelete();
            $table->index('informational_keyword_planner_run_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('keywords') || ! Schema::hasColumn('keywords', 'informational_keyword_planner_run_id')) {
            return;
        }

        Schema::table('keywords', function (Blueprint $table) {
            $table->dropForeign(['informational_keyword_planner_run_id']);
        });
    }
};
