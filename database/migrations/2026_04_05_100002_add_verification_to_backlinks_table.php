<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backlinks', function (Blueprint $table) {
            $table->string('verification_status', 32)->default('pending')->after('backlink_spam_score');
            $table->timestamp('verified_at')->nullable()->after('verification_status');
            $table->index(['verification_status', 'domain']);
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::table('backlinks')->where('safe_browsing_status', '!=', 'unknown')
                ->whereNotNull('safe_browsing_checked_at')
                ->update([
                    'verification_status' => 'verified',
                    'verified_at' => DB::raw('COALESCE(safe_browsing_checked_at, updated_at)'),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('backlinks', function (Blueprint $table) {
            $table->dropIndex(['verification_status', 'domain']);
            $table->dropColumn(['verification_status', 'verified_at']);
        });
    }
};
