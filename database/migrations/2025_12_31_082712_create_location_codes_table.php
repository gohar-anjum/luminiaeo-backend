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
        Schema::create('location_codes', function (Blueprint $table) {
            $table->id();
            $table->integer('location_code')->unique();
            $table->string('location_name');
            $table->integer('location_code_parent')->nullable();
            $table->string('country_iso_code', 2)->nullable();
            $table->string('location_type')->nullable(); // Country, Region, etc.
            $table->timestamps();
            
            $table->index('location_code');
            $table->index('country_iso_code');
            $table->index('location_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('location_codes');
    }
};
