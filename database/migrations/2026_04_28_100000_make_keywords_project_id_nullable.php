<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('keywords') || ! Schema::hasColumn('keywords', 'project_id')) {
            return;
        }

        try {
            Schema::table('keywords', function (Blueprint $table) {
                $table->dropForeign(['project_id']);
            });
        } catch (\Throwable) {
        }

        Schema::table('keywords', function (Blueprint $table) {
            $table->unsignedBigInteger('project_id')->nullable()->change();
        });
    }

    public function down(): void {}
};
