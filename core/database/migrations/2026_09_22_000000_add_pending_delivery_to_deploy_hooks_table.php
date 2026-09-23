<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deploy_hooks', function (Blueprint $table) {
            // Coalescing: the one delivery, if any, that arrived while this
            // hook's project already had a deploy running, waiting for
            // DeployLogger::finish() -- the one place every deploy path
            // passes through -- to run it. No foreign key: SQLite (the test
            // suite's connection) cannot add one to an existing table without
            // rebuilding it, and the row is cleared, never orphaned, by the
            // same code that reads it.
            $table->unsignedBigInteger('pending_delivery_id')->nullable()->after('secret_encrypted');
        });
    }

    public function down(): void
    {
        Schema::table('deploy_hooks', function (Blueprint $table) {
            $table->dropColumn('pending_delivery_id');
        });
    }
};
