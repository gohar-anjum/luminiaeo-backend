<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('backlinks', function (Blueprint $table) {
            $table->unsignedTinyInteger('backlink_spam_score')->nullable()->after('safe_browsing_checked_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('backlinks', function (Blueprint $table) {
            $table->dropColumn('backlink_spam_score');
        });
    }
};
