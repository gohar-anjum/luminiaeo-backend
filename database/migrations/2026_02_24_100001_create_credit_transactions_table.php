<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32); // purchase, usage, refund, bonus, adjustment
            $table->bigInteger('amount'); // positive = credit in, negative = credit out
            $table->unsignedBigInteger('balance_after')->nullable(); // snapshot after this tx for audit
            $table->string('feature_key')->nullable(); // for usage: which feature consumed
            $table->string('reference_type')->nullable(); // e.g. stripe_payment_intent, stripe_refund
            $table->string('reference_id')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
    }
};
