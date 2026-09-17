<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proxy_rules', function (Blueprint $table) {
            $table->id();

            // Ownership
            $table->enum('owner_scope', ['system', 'user'])->default('system')->index();
            $username = $table->string('username')->nullable()->index(); // For user-owned rules
            // MySQL's utf8mb4_unicode_ci makes username lookups case-insensitive; match that on sqlite.
            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                $username->collation('NOCASE');
            }

            // Enable/disable
            $table->boolean('enabled')->default(true)->index();

            // Listen configuration
            $table->enum('transport', ['http', 'tcp', 'udp'])->index();
            $table->string('listen_ip')->nullable()->default('*'); // * for 0.0.0.0
            $table->unsignedInteger('listen_port')->index();
            $table->string('server_name')->nullable()->index(); // For HTTP rules only

            // Upstream target
            $table->string('upstream_host');
            $table->unsignedInteger('upstream_port');
            $table->string('upstream_protocol')->nullable(); // http, https, etc. for HTTP; null for stream

            // System tracking
            $table->boolean('is_generated')->default(false)->index(); // true if auto-created from project detection
            $table->json('metadata')->nullable(); // For source tracking, original port, etc.

            $table->timestamps();

            // Composite index for conflict detection (explicit name to avoid MySQL identifier length limit)
            $table->index(['transport', 'listen_ip', 'listen_port', 'server_name', 'enabled'], 'proxy_rules_conflict_idx');

            // Foreign key to users table
            $table->foreign('username')
                ->references('username')
                ->on('users')
                ->onDelete('cascade')
                ->onUpdate('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proxy_rules');
    }
};
