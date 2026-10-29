<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nullable: rows written before this column existed have no reading.
        Schema::table('server_metrics', function (Blueprint $table) {
            $table->float('disk_percent')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('server_metrics', function (Blueprint $table) {
            $table->dropColumn('disk_percent');
        });
    }
};
