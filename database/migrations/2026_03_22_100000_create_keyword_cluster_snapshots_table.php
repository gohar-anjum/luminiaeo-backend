<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('keyword_cluster_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('cache_key', 64)->unique();
            $table->string('keyword', 255)->index();
            $table->string('language_code', 8)->default('en');
            $table->unsignedInteger('location_code')->default(2840);
            $table->json('tree_json');
            $table->timestamp('expires_at')->index();
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->timestamps();

            $table->index(['expires_at', 'schema_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('keyword_cluster_snapshots');
    }
};
