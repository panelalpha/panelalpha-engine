<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wordpress_migrations', function (Blueprint $table) {
            $table->string('run_id', 64)->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->string('created_database')->nullable();
            $table->string('created_database_user')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('wordpress_migrations', function (Blueprint $table) {
            $table->dropColumn(['run_id', 'heartbeat_at', 'created_database', 'created_database_user']);
        });
    }
};
