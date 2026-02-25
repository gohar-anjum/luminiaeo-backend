<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('keyword_research_jobs')) {
            return;
        }
        Schema::table('keyword_research_jobs', function (Blueprint $table) {
            $table->unsignedBigInteger('credit_reservation_id')->nullable()->after('user_id')->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('keyword_research_jobs')) {
            return;
        }
        Schema::table('keyword_research_jobs', function (Blueprint $table) {
            $table->dropColumn('credit_reservation_id');
        });
    }
};
