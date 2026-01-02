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
        Schema::table('faq_tasks', function (Blueprint $table) {
            $table->json('serp_answers')->nullable()->after('serp_questions');
            $table->json('paa_answers')->nullable()->after('alsoasked_questions');
            $table->string('search_keyword')->nullable()->after('topic'); // Store the keyword used for search
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('faq_tasks', function (Blueprint $table) {
            $table->dropColumn(['serp_answers', 'paa_answers', 'search_keyword']);
        });
    }
};
