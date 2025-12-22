<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('citation_tasks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('url');
            $table->string('status', 32)->default('pending');
            $table->json('queries')->nullable();
            $table->json('results')->nullable();
            $table->json('competitors')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('url');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('citation_tasks');
    }
};
