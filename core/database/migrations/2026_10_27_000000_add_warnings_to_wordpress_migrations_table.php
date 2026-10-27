<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wordpress_migrations', function (Blueprint $table) {
            $table->json('warnings')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('wordpress_migrations', function (Blueprint $table) {
            $table->dropColumn('warnings');
        });
    }
};
