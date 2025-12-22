<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {

        if (Schema::hasTable('keywords')) {
            Schema::table('keywords', function (Blueprint $table) {

                if (!$this->indexExists('keywords', 'idx_keywords_research_job')) {
                    $table->index('keyword_research_job_id', 'idx_keywords_research_job');
                }
                if (!$this->indexExists('keywords', 'idx_keywords_cluster')) {
                    $table->index('keyword_cluster_id', 'idx_keywords_cluster');
                }
                if (!$this->indexExists('keywords', 'idx_keywords_source')) {
                    $table->index('source', 'idx_keywords_source');
                }
                if (!$this->indexExists('keywords', 'idx_keywords_keyword')) {
                    $table->index('keyword', 'idx_keywords_keyword');
                }
                if (!$this->indexExists('keywords', 'idx_keywords_intent')) {
                    $table->index('intent_category', 'idx_keywords_intent');
                }

                if (!$this->indexExists('keywords', 'idx_keywords_job_source')) {
                    $table->index(['keyword_research_job_id', 'source'], 'idx_keywords_job_source');
                }
                if (!$this->indexExists('keywords', 'idx_keywords_job_cluster')) {
                    $table->index(['keyword_research_job_id', 'keyword_cluster_id'], 'idx_keywords_job_cluster');
                }
                if (!$this->indexExists('keywords', 'idx_keywords_visibility')) {
                    $table->index('ai_visibility_score', 'idx_keywords_visibility');
                }
            });
        }

        if (Schema::hasTable('keyword_research_jobs')) {
            Schema::table('keyword_research_jobs', function (Blueprint $table) {
                if (!$this->indexExists('keyword_research_jobs', 'idx_research_jobs_user')) {
                    $table->index('user_id', 'idx_research_jobs_user');
                }
                if (!$this->indexExists('keyword_research_jobs', 'idx_research_jobs_status')) {
                    $table->index('status', 'idx_research_jobs_status');
                }
                if (!$this->indexExists('keyword_research_jobs', 'idx_research_jobs_project')) {
                    $table->index('project_id', 'idx_research_jobs_project');
                }

                if (!$this->indexExists('keyword_research_jobs', 'idx_research_jobs_user_status')) {
                    $table->index(['user_id', 'status'], 'idx_research_jobs_user_status');
                }

                if (!$this->indexExists('keyword_research_jobs', 'idx_research_jobs_created')) {
                    $table->index('created_at', 'idx_research_jobs_created');
                }
            });
        }

        if (Schema::hasTable('keyword_clusters')) {
            Schema::table('keyword_clusters', function (Blueprint $table) {
                if (!$this->indexExists('keyword_clusters', 'idx_clusters_job')) {
                    $table->index('keyword_research_job_id', 'idx_clusters_job');
                }
            });
        }

        if (Schema::hasTable('citation_tasks')) {
            Schema::table('citation_tasks', function (Blueprint $table) {
                if (!$this->indexExists('citation_tasks', 'idx_citation_tasks_status')) {
                    $table->index('status', 'idx_citation_tasks_status');
                }
                if (!$this->indexExists('citation_tasks', 'idx_citation_tasks_url')) {
                    $table->index('url', 'idx_citation_tasks_url');
                }
                if (!$this->indexExists('citation_tasks', 'idx_citation_tasks_created')) {
                    $table->index('created_at', 'idx_citation_tasks_created');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('keywords')) {
            Schema::table('keywords', function (Blueprint $table) {
                $this->dropIndexIfExists($table, 'idx_keywords_research_job');
                $this->dropIndexIfExists($table, 'idx_keywords_cluster');
                $this->dropIndexIfExists($table, 'idx_keywords_source');
                $this->dropIndexIfExists($table, 'idx_keywords_keyword');
                $this->dropIndexIfExists($table, 'idx_keywords_intent');
                $this->dropIndexIfExists($table, 'idx_keywords_job_source');
                $this->dropIndexIfExists($table, 'idx_keywords_job_cluster');
                $this->dropIndexIfExists($table, 'idx_keywords_visibility');
            });
        }

        if (Schema::hasTable('keyword_research_jobs')) {
            Schema::table('keyword_research_jobs', function (Blueprint $table) {
                $this->dropIndexIfExists($table, 'idx_research_jobs_user');
                $this->dropIndexIfExists($table, 'idx_research_jobs_status');
                $this->dropIndexIfExists($table, 'idx_research_jobs_project');
                $this->dropIndexIfExists($table, 'idx_research_jobs_user_status');
                $this->dropIndexIfExists($table, 'idx_research_jobs_created');
            });
        }

        if (Schema::hasTable('keyword_clusters')) {
            Schema::table('keyword_clusters', function (Blueprint $table) {
                $this->dropIndexIfExists($table, 'idx_clusters_job');
            });
        }

        if (Schema::hasTable('citation_tasks')) {
            Schema::table('citation_tasks', function (Blueprint $table) {
                $this->dropIndexIfExists($table, 'idx_citation_tasks_status');
                $this->dropIndexIfExists($table, 'idx_citation_tasks_url');
                $this->dropIndexIfExists($table, 'idx_citation_tasks_created');
            });
        }
    }

    protected function indexExists(string $table, string $indexName): bool
    {
        try {
            $connection = DB::connection();
            $databaseName = $connection->getDatabaseName();
            $tableName = $connection->getTablePrefix() . $table;

            $result = DB::selectOne(
                "SELECT COUNT(*) as count FROM information_schema.statistics
                 WHERE table_schema = ? AND table_name = ? AND index_name = ?",
                [$databaseName, $tableName, $indexName]
            );
            return isset($result) && $result->count > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function dropIndexIfExists(Blueprint $table, string $indexName): void
    {
        try {
            $table->dropIndex($indexName);
        } catch (\Exception $e) {

        }
    }
};
