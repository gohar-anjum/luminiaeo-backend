<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cluster_jobs')) {
            return;
        }

        Schema::table('cluster_jobs', function (Blueprint $table) {
            if (! Schema::hasColumn('cluster_jobs', 'credit_reservation_id')) {
                $table->foreignId('credit_reservation_id')
                    ->nullable()
                    ->after('user_id')
                    ->constrained('credit_transactions')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('cluster_jobs')) {
            return;
        }

        Schema::table('cluster_jobs', function (Blueprint $table) {
            if (Schema::hasColumn('cluster_jobs', 'credit_reservation_id')) {
                $table->dropConstrainedForeignId('credit_reservation_id');
            }
        });
    }
};
