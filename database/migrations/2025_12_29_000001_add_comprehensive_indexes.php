<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Keyword Research Jobs - Add missing indexes
        if (Schema::hasTable('keyword_research_jobs')) {
            Schema::table('keyword_research_jobs', function (Blueprint $table) {
                // Composite index for user job listing with status filtering
                if (!$this->indexExists('keyword_research_jobs', 'keyword_research_jobs_user_id_status_index')) {
                    $table->index(['user_id', 'status', 'created_at'], 'keyword_research_jobs_user_id_status_index');
                }
                // Index for status filtering
                if (!$this->indexExists('keyword_research_jobs', 'keyword_research_jobs_status_index')) {
                    $table->index('status');
                }
                // Index for query searches
                if (!$this->indexExists('keyword_research_jobs', 'keyword_research_jobs_query_index')) {
                    $table->index('query');
                }
            });
        }

        // Citation Tasks - Add missing indexes
        if (Schema::hasTable('citation_tasks')) {
            Schema::table('citation_tasks', function (Blueprint $table) {
                // Composite index for cache lookups
                if (!$this->indexExists('citation_tasks', 'citation_tasks_url_status_created_at_index')) {
                    $table->index(['url', 'status', 'created_at'], 'citation_tasks_url_status_created_at_index');
                }
                // Index for in-progress task lookups
                if (!$this->indexExists('citation_tasks', 'citation_tasks_status_created_at_index')) {
                    $table->index(['status', 'created_at']);
                }
            });
        }

        // Backlinks - Add missing indexes
        if (Schema::hasTable('backlinks')) {
            Schema::table('backlinks', function (Blueprint $table) {
                // Composite unique index for upsert operations
                if (!$this->indexExists('backlinks', 'backlinks_domain_source_url_task_id_unique')) {
                    $table->unique(['domain', 'source_url', 'task_id'], 'backlinks_domain_source_url_task_id_unique');
                }
                // Index for domain queries
                if (!$this->indexExists('backlinks', 'backlinks_domain_index')) {
                    $table->index('domain');
                }
                // Index for risk level filtering
                if (!$this->indexExists('backlinks', 'backlinks_risk_level_index')) {
                    $table->index('risk_level');
                }
                // Index for PBN probability sorting
                if (!$this->indexExists('backlinks', 'backlinks_pbn_probability_index')) {
                    $table->index('pbn_probability');
                }
                // Composite index for domain + risk level queries
                if (!$this->indexExists('backlinks', 'backlinks_domain_risk_level_index')) {
                    $table->index(['domain', 'risk_level']);
                }
            });
        }

        // SEO Tasks - Add missing indexes
        if (Schema::hasTable('seo_tasks')) {
            Schema::table('seo_tasks', function (Blueprint $table) {
                // Composite index for cache lookups (domain + type + status + completed_at)
                if (!$this->indexExists('seo_tasks', 'seo_tasks_domain_type_status_completed_at_index')) {
                    $table->index(['domain', 'type', 'status', 'completed_at'], 'seo_tasks_domain_type_status_completed_at_index');
                }
            });
        }

        // Keyword Cache - Add missing indexes
        if (Schema::hasTable('keyword_cache')) {
            Schema::table('keyword_cache', function (Blueprint $table) {
                // Index for source filtering
                if (!$this->indexExists('keyword_cache', 'keyword_cache_source_index')) {
                    $table->index('source');
                }
                // Index for cluster lookups
                if (!$this->indexExists('keyword_cache', 'keyword_cache_cluster_id_index')) {
                    $table->index('cluster_id');
                }
            });
        }

        // PBN Detections - Add missing indexes
        if (Schema::hasTable('pbn_detections')) {
            Schema::table('pbn_detections', function (Blueprint $table) {
                // Composite index for task + domain lookups
                if (!$this->indexExists('pbn_detections', 'pbn_detections_task_id_domain_index')) {
                    $table->index(['task_id', 'domain']);
                }
                // Index for status filtering
                if (!$this->indexExists('pbn_detections', 'pbn_detections_status_index')) {
                    $table->index('status');
                }
            });
        }

        // FAQs - Indexes already exist, but verify
        if (Schema::hasTable('faqs')) {
            Schema::table('faqs', function (Blueprint $table) {
                // Ensure source_hash is unique and indexed (should already exist)
                if (!$this->indexExists('faqs', 'faqs_source_hash_unique')) {
                    $table->unique('source_hash');
                }
            });
        }
    }

    public function down(): void
    {
        // Drop indexes in reverse order
        if (Schema::hasTable('keyword_research_jobs')) {
            Schema::table('keyword_research_jobs', function (Blueprint $table) {
                $table->dropIndex('keyword_research_jobs_user_id_status_index');
                $table->dropIndex('keyword_research_jobs_status_index');
                $table->dropIndex('keyword_research_jobs_query_index');
            });
        }

        if (Schema::hasTable('citation_tasks')) {
            Schema::table('citation_tasks', function (Blueprint $table) {
                $table->dropIndex('citation_tasks_url_status_created_at_index');
                $table->dropIndex('citation_tasks_status_created_at_index');
            });
        }

        if (Schema::hasTable('backlinks')) {
            Schema::table('backlinks', function (Blueprint $table) {
                $table->dropUnique('backlinks_domain_source_url_task_id_unique');
                $table->dropIndex('backlinks_domain_index');
                $table->dropIndex('backlinks_risk_level_index');
                $table->dropIndex('backlinks_pbn_probability_index');
                $table->dropIndex('backlinks_domain_risk_level_index');
            });
        }

        if (Schema::hasTable('seo_tasks')) {
            Schema::table('seo_tasks', function (Blueprint $table) {
                $table->dropIndex('seo_tasks_domain_type_status_completed_at_index');
            });
        }

        if (Schema::hasTable('keyword_cache')) {
            Schema::table('keyword_cache', function (Blueprint $table) {
                $table->dropIndex('keyword_cache_source_index');
                $table->dropIndex('keyword_cache_cluster_id_index');
            });
        }

        if (Schema::hasTable('pbn_detections')) {
            Schema::table('pbn_detections', function (Blueprint $table) {
                $table->dropIndex('pbn_detections_task_id_domain_index');
                $table->dropIndex('pbn_detections_status_index');
            });
        }
    }

    /**
     * Check if an index exists on a table
     */
    private function indexExists(string $table, string $indexName): bool
    {
        $connection = Schema::getConnection();
        $database = $connection->getDatabaseName();
        
        $result = $connection->select(
            "SELECT COUNT(*) as count FROM information_schema.statistics 
             WHERE table_schema = ? AND table_name = ? AND index_name = ?",
            [$database, $table, $indexName]
        );
        
        return $result[0]->count > 0;
    }
};

