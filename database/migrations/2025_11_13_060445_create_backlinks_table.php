<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('backlinks', function (Blueprint $table) {
            $table->id();
            $table->string('domain');
            $table->string('source_url');
            $table->string('anchor')->nullable();
            $table->enum('link_type', ['dofollow', 'nofollow'])->nullable();
            $table->string('source_domain')->nullable();
            $table->float('domain_rank')->nullable();
            $table->string('task_id')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backlinks');
    }
};
