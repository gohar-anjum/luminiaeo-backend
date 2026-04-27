<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('keywords') && Schema::hasColumn('keywords', 'informational_keyword_planner_run_id')) {
            Schema::table('keywords', function (Blueprint $table) {
                $table->dropForeign(['informational_keyword_planner_run_id']);
            });
        }

        if (Schema::hasTable('informational_keyword_planner_runs')) {
            Schema::dropIfExists('informational_keyword_planner_runs');
        }
    }

    public function down(): void {}
};
