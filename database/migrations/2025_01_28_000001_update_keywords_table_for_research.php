<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {

        if (!Schema::hasTable('keyword_research_jobs')) {

            return;
        }

        if (!Schema::hasTable('keyword_clusters')) {
        Schema::create('keyword_clusters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('keyword_research_job_id')->constrained()->cascadeOnDelete();
            $table->string('topic_name');
            $table->text('description')->nullable();
            $table->json('suggested_article_titles')->nullable();
            $table->json('recommended_faq_questions')->nullable();
            $table->json('schema_suggestions')->nullable();
            $table->float('ai_visibility_projection')->nullable();
            $table->integer('keyword_count')->default(0);
            $table->timestamps();

            $table->index(['keyword_research_job_id']);
        });
        }

        if (!Schema::hasTable('keywords')) {
            return;
        }

        Schema::table('keywords', function (Blueprint $table) {
            $table->foreignId('keyword_research_job_id')->nullable()->after('id')->constrained('keyword_research_jobs')->onDelete('cascade');
            $table->foreignId('keyword_cluster_id')->nullable()->after('keyword_research_job_id')->constrained('keyword_clusters')->onDelete('set null');
            $table->string('source')->nullable()->after('keyword');
            $table->json('question_variations')->nullable()->after('intent');
            $table->json('long_tail_versions')->nullable()->after('question_variations');
            $table->float('ai_visibility_score')->nullable()->after('cpc');
            $table->string('intent_category')->nullable()->after('intent');
            $table->json('intent_metadata')->nullable()->after('intent_category');
            $table->json('semantic_data')->nullable()->after('intent_metadata');
            $table->string('language_code')->default('en')->after('location');
            $table->integer('geoTargetId')->nullable()->after('language_code');
            $table->index(['keyword_research_job_id']);
            $table->index(['keyword_cluster_id']);
            $table->index(['source']);
            $table->index(['intent_category']);
        });

        Schema::table('keyword_research_jobs', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('user_id')->constrained()->onDelete('cascade');
            $table->string('language_code')->default('en')->after('query');
            $table->integer('geoTargetId')->default(2840)->after('language_code');
            $table->json('settings')->nullable()->after('status');
            $table->json('progress')->nullable()->after('settings');
            $table->text('error_message')->nullable()->after('result');
            $table->timestamp('started_at')->nullable()->after('error_message');
            $table->timestamp('completed_at')->nullable()->after('started_at');

            $table->index(['user_id', 'status']);
            $table->index(['project_id']);
        });
    }

    public function down(): void
    {
        Schema::table('keywords', function (Blueprint $table) {
            $table->dropForeign(['keyword_research_job_id']);
            $table->dropForeign(['keyword_cluster_id']);
            $table->dropIndex(['keyword_research_job_id']);
            $table->dropIndex(['keyword_cluster_id']);
            $table->dropIndex(['source']);
            $table->dropIndex(['intent_category']);
            $table->dropColumn([
                'keyword_research_job_id',
                'keyword_cluster_id',
                'source',
                'question_variations',
                'long_tail_versions',
                'ai_visibility_score',
                'intent_category',
                'intent_metadata',
                'semantic_data',
                'language_code',
                'geoTargetId',
            ]);
        });

        Schema::dropIfExists('keyword_clusters');

        Schema::table('keyword_research_jobs', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropIndex(['user_id', 'status']);
            $table->dropIndex(['project_id']);
            $table->dropColumn([
                'project_id',
                'language_code',
                'geoTargetId',
                'settings',
                'progress',
                'error_message',
                'started_at',
                'completed_at',
            ]);
        });
    }
};
