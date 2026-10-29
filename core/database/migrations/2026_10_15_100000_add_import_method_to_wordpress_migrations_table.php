<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wordpress_migrations', function (Blueprint $table) {
            $table->string('method', 32)->default('wp_admin');
            $table->string('login_url')->nullable();
            $table->string('rest_mode', 16)->nullable();
            $table->string('source_ip', 45)->nullable();
            $table->text('source_token')->nullable();
            $table->string('username')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('wordpress_migrations', function (Blueprint $table) {
            $table->dropColumn(['method', 'login_url', 'rest_mode', 'source_ip', 'source_token']);
        });
    }
};
