<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('backlinks', function (Blueprint $table) {
            $table->id();
            $table->string('domain');                   // Target domain
            $table->string('source_url');               // Referring page
            $table->string('anchor')->nullable();       // Anchor text
            $table->enum('link_type', ['dofollow', 'nofollow'])->nullable();
            $table->string('source_domain')->nullable();// Referring domain
            $table->float('domain_rank')->nullable();   // Metric from DataForSEO
            $table->string('task_id')->index();         // DataForSEO task reference
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backlinks');
    }
};
