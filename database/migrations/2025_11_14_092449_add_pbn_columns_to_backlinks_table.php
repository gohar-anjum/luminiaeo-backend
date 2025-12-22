<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backlinks', function (Blueprint $table) {
            $table->string('ip')->nullable()->after('task_id');
            $table->string('asn')->nullable()->after('ip');
            $table->string('hosting_provider')->nullable()->after('asn');
            $table->string('whois_registrar')->nullable()->after('hosting_provider');
            $table->unsignedInteger('domain_age_days')->nullable()->after('whois_registrar');
            $table->string('content_fingerprint', 191)->nullable()->after('domain_age_days');
            $table->decimal('pbn_probability', 5, 4)->nullable()->after('content_fingerprint');
            $table->string('risk_level')->default('unknown')->after('pbn_probability');
            $table->json('pbn_reasons')->nullable()->after('risk_level');
            $table->json('pbn_signals')->nullable()->after('pbn_reasons');
            $table->string('safe_browsing_status')->default('unknown')->after('pbn_signals');
            $table->json('safe_browsing_threats')->nullable()->after('safe_browsing_status');
            $table->timestamp('safe_browsing_checked_at')->nullable()->after('safe_browsing_threats');
        });
    }

    public function down(): void
    {
        Schema::table('backlinks', function (Blueprint $table) {
            $table->dropColumn([
                'ip',
                'asn',
                'hosting_provider',
                'whois_registrar',
                'domain_age_days',
                'content_fingerprint',
                'pbn_probability',
                'risk_level',
                'pbn_reasons',
                'pbn_signals',
                'safe_browsing_status',
                'safe_browsing_threats',
                'safe_browsing_checked_at',
            ]);
        });
    }
};
