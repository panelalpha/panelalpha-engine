<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hourly averages of the per-minute project samples. The minute rows are kept
 * two days; these keep the month that the 7d and 30d charts read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_metrics_hourly', function (Blueprint $table) {
            $table->id();
            $table->dateTime('timestamp');
            $table->string('username');
            $table->float('cpu_percent')->nullable();
            $table->unsignedBigInteger('memory_bytes')->nullable();
            $table->unsignedBigInteger('memory_limit_bytes')->nullable();
            $table->unsignedInteger('samples');
            $table->unique(['username', 'timestamp']);
            $table->index('timestamp');
        });

        Schema::create('project_container_metrics_hourly', function (Blueprint $table) {
            $table->id();
            $table->dateTime('timestamp');
            $table->string('username');
            $table->string('container_id', 64);
            $table->string('name')->nullable();
            $table->string('service')->nullable();
            $table->float('cpu_percent')->nullable();
            $table->unsignedBigInteger('memory_bytes')->nullable();
            $table->unsignedBigInteger('memory_limit_bytes')->nullable();
            $table->unsignedInteger('samples');
            $table->unique(['container_id', 'timestamp']);
            $table->index(['username', 'timestamp']);
            $table->index('timestamp');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_container_metrics_hourly');
        Schema::dropIfExists('project_metrics_hourly');
    }
};
