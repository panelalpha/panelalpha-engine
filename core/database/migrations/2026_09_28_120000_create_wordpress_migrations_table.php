<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wordpress_migrations', function (Blueprint $table) {
            $table->id();
            $table->string('source_url');
            $table->string('username');
            $table->string('target_label')->nullable();
            $table->string('status', 32);
            $table->json('steps');
            $table->json('step_log')->nullable();
            // Latest batch progress of the running transfer step (phase, percentage, current_item).
            $table->json('progress')->nullable();
            // Encrypted at rest via the model cast; never written to step_log.
            $table->text('password')->nullable();
            $table->text('migrator_token')->nullable();
            // Plugin remote-sync id; needed by steps 6–8 and by resume after a mid-transfer failure.
            $table->string('plugin_migration_id')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wordpress_migrations');
    }
};
