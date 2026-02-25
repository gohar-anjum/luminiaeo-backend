<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stripe_events', function (Blueprint $table) {
            $table->id();
            $table->string('stripe_event_id', 255)->unique();
            $table->string('type', 128)->nullable();
            $table->timestamp('processed_at');
            $table->timestamps();

            $table->index('stripe_event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_events');
    }
};
