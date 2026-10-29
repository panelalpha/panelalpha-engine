<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each project uses, once a minute: its DinD container as a whole, and
 * every container running inside it. CPU time is kept so the next sample can
 * turn it into a percentage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_metrics', function (Blueprint $table) {
            $table->id();
            $table->dateTime('timestamp');
            $table->string('username');
            $table->float('cpu_percent')->nullable();
            $table->unsignedBigInteger('memory_bytes')->nullable();
            $table->unsignedBigInteger('memory_limit_bytes')->nullable();
            $table->unsignedBigInteger('cpu_usage_usec')->nullable();
            $table->index(['username', 'timestamp']);
            $table->index('timestamp');
        });

        Schema::create('project_container_metrics', function (Blueprint $table) {
            $table->id();
            $table->dateTime('timestamp');
            $table->string('username');
            $table->string('container_id', 64);
            $table->string('name')->nullable();
            $table->string('service')->nullable();
            $table->float('cpu_percent')->nullable();
            $table->unsignedBigInteger('memory_bytes')->nullable();
            $table->unsignedBigInteger('memory_limit_bytes')->nullable();
            $table->unsignedBigInteger('cpu_usage_usec')->nullable();
            $table->index(['username', 'timestamp']);
            $table->index('timestamp');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_container_metrics');
        Schema::dropIfExists('project_metrics');
    }
};
