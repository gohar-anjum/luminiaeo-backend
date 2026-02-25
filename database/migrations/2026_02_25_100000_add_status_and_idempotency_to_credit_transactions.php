<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_transactions', function (Blueprint $table) {
            $table->string('status', 24)->default('completed')->after('metadata');
            $table->string('idempotency_key', 64)->nullable()->after('status');
        });

        Schema::table('credit_transactions', function (Blueprint $table) {
            $table->unique('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::table('credit_transactions', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn(['status', 'idempotency_key']);
        });
    }
};
