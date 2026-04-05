<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Superseded: inbound logging was duplicate of api_request_logs (ApiCacheService).
     * New installs: no-op. If an older copy of this migration already created the table, run
     * 2026_04_06_100000_drop_client_api_request_logs_table as well.
     */
    public function up(): void
    {
        Schema::dropIfExists('client_api_request_logs');
    }

    public function down(): void {}
};
