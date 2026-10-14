<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('container_id')->constrained('backup_containers')->restrictOnDelete();
            $table->string('cron');
            $table->unsignedSmallInteger('keep_last')->default(7);
            $table->boolean('files')->default(true);
            $table->boolean('databases')->default(true);
            $table->boolean('enabled')->default(true);
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->foreignId('last_backup_id')->nullable()->constrained('backups')->nullOnDelete();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['enabled', 'next_run_at']);
            $table->index('container_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_schedules');
    }
};
