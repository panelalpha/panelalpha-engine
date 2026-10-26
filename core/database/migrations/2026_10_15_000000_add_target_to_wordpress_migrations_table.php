<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wordpress_migrations', function (Blueprint $table) {
            $table->string('target_project')->nullable();
            $table->boolean('created_project')->default(true);
            $table->string('preview_hostname')->nullable();
            $table->timestamp('dns_switched_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('wordpress_migrations', function (Blueprint $table) {
            $table->dropColumn(['target_project', 'created_project', 'preview_hostname', 'dns_switched_at']);
        });
    }
};
