<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each project's transfer this calendar month, read from the statistics in
 * the background so listing projects never reads them. One row per project.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_bandwidth_usage', function (Blueprint $table) {
            $table->id();
            $table->string('username')->unique();
            $table->char('month', 7);
            $table->unsignedBigInteger('used_bytes');
            $table->dateTime('measured_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_bandwidth_usage');
    }
};
