<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pbn_detections', function (Blueprint $table) {
            $table->id();
            $table->string('task_id')->index();
            $table->string('domain')->index();
            $table->string('status')->default('pending');
            $table->unsignedInteger('high_risk_count')->default(0);
            $table->unsignedInteger('medium_risk_count')->default(0);
            $table->unsignedInteger('low_risk_count')->default(0);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamp('analysis_started_at')->nullable();
            $table->timestamp('analysis_completed_at')->nullable();
            $table->string('status_message')->nullable();
            $table->json('summary')->nullable();
            $table->json('response_payload')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pbn_detections');
    }
};
