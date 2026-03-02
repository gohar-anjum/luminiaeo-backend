<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('semantic_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('source_url')->index();
            $table->string('comparison_type'); // text | url
            $table->text('comparison_value');

            $table->float('semantic_score');

            $table->timestamp('analyzed_at');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('semantic_analyses');
    }
};
