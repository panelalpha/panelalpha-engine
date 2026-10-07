<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each project keeps on disk, measured in the background so no request
 * waits on `du`: its files, and its own Docker's data (images, build cache,
 * containers, volumes) under ~/docker.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_disk_usage', function (Blueprint $table) {
            $table->id();
            $table->dateTime('timestamp');
            $table->string('username');
            $table->unsignedBigInteger('files_bytes')->nullable();
            $table->unsignedBigInteger('docker_bytes')->nullable();
            $table->index(['username', 'timestamp']);
            $table->index('timestamp');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_disk_usage');
    }
};
