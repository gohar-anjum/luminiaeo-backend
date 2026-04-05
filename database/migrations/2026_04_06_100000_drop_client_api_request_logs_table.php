<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admin API logs use the existing api_request_logs table (ApiCacheService).
     */
    public function up(): void
    {
        Schema::dropIfExists('client_api_request_logs');
    }

    public function down(): void
    {
        // Intentionally empty: duplicate logging was removed.
    }
};
