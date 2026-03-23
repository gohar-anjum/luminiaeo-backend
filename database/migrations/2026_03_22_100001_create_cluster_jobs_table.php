<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cluster_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('cache_key', 64)->index();
            $table->string('keyword', 255);
            $table->string('language_code', 8)->default('en');
            $table->unsignedInteger('location_code')->default(2840);
            $table->string('status')->default('pending')->index();
            $table->text('error_message')->nullable();
            $table->foreignId('snapshot_id')->nullable()->constrained('keyword_cluster_snapshots')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['cache_key', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cluster_jobs');
    }
};
