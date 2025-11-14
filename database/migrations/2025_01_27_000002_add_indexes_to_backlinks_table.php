<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Check if indexes exist before creating them
        $connection = DB::connection();
        $databaseName = $connection->getDatabaseName();
        $tableName = $connection->getTablePrefix() . 'backlinks';

        Schema::table('backlinks', function (Blueprint $table) use ($databaseName, $tableName) {
            // Add indexes on frequently queried columns (only if they don't exist)
            if (!$this->indexExists($databaseName, $tableName, 'idx_backlinks_domain')) {
                $table->index('domain', 'idx_backlinks_domain');
            }
            if (!$this->indexExists($databaseName, $tableName, 'idx_backlinks_source_url')) {
                $table->index('source_url', 'idx_backlinks_source_url');
            }
            if (!$this->indexExists($databaseName, $tableName, 'idx_backlinks_source_domain')) {
                $table->index('source_domain', 'idx_backlinks_source_domain');
            }
            if (!$this->indexExists($databaseName, $tableName, 'idx_backlinks_domain_rank')) {
                $table->index('domain_rank', 'idx_backlinks_domain_rank');
            }
            if (!$this->indexExists($databaseName, $tableName, 'idx_backlinks_created_at')) {
                $table->index('created_at', 'idx_backlinks_created_at');
            }

            // Add composite indexes for common query patterns
            if (!$this->indexExists($databaseName, $tableName, 'idx_backlinks_domain_source')) {
                $table->index(['domain', 'source_url'], 'idx_backlinks_domain_source');
            }
            if (!$this->indexExists($databaseName, $tableName, 'idx_backlinks_domain_rank_composite')) {
                $table->index(['domain', 'domain_rank'], 'idx_backlinks_domain_rank_composite');
            }
            if (!$this->indexExists($databaseName, $tableName, 'idx_backlinks_domain_created')) {
                $table->index(['domain', 'created_at'], 'idx_backlinks_domain_created');
            }
            if (!$this->indexExists($databaseName, $tableName, 'idx_backlinks_task_domain')) {
                $table->index(['task_id', 'domain'], 'idx_backlinks_task_domain');
            }

            // Add unique constraint to prevent duplicate backlinks
            if (!$this->indexExists($databaseName, $tableName, 'idx_backlinks_unique')) {
                try {
                    $table->unique(['domain', 'source_url', 'task_id'], 'idx_backlinks_unique');
                } catch (\Exception $e) {
                    // Ignore if unique constraint already exists
                }
            }
        });
    }

    /**
     * Check if an index exists on a table
     */
    protected function indexExists(string $databaseName, string $tableName, string $indexName): bool
    {
        try {
            $result = DB::selectOne(
                "SELECT COUNT(*) as count FROM information_schema.statistics 
                 WHERE table_schema = ? AND table_name = ? AND index_name = ?",
                [$databaseName, $tableName, $indexName]
            );
            return isset($result) && $result->count > 0;
        } catch (\Exception $e) {
            // If query fails (e.g., SQLite), assume index doesn't exist and try to create it
            return false;
        }
    }

    public function down(): void
    {
        Schema::table('backlinks', function (Blueprint $table) {
            // Drop indexes (safe to drop even if they don't exist)
            try {
                $table->dropIndex('idx_backlinks_domain');
                $table->dropIndex('idx_backlinks_source_url');
                $table->dropIndex('idx_backlinks_source_domain');
                $table->dropIndex('idx_backlinks_domain_rank');
                $table->dropIndex('idx_backlinks_created_at');
                $table->dropIndex('idx_backlinks_domain_source');
                $table->dropIndex('idx_backlinks_domain_rank_composite');
                $table->dropIndex('idx_backlinks_domain_created');
                $table->dropIndex('idx_backlinks_task_domain');
                $table->dropUnique('idx_backlinks_unique');
            } catch (\Exception $e) {
                // Ignore errors when dropping indexes that don't exist
            }
        });
    }
};

