<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_analyses', function (Blueprint $table) {
            $table->string('target_keyword')->nullable()->after('url');
            $table->json('suggestions')->nullable()->after('suggested_description');
        });

        Schema::table('semantic_analyses', function (Blueprint $table) {
            $table->string('target_keyword')->nullable()->after('source_url');
            $table->json('keyword_scores')->nullable()->after('semantic_score');
        });
    }

    public function down(): void
    {
        Schema::table('meta_analyses', function (Blueprint $table) {
            $table->dropColumn(['target_keyword', 'suggestions']);
        });

        Schema::table('semantic_analyses', function (Blueprint $table) {
            $table->dropColumn(['target_keyword', 'keyword_scores']);
        });
    }
};
