<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hub_connections', function (Blueprint $table) {
            $table->string('caller_ip', 45)->nullable()->after('ip');
        });
    }

    public function down(): void
    {
        Schema::table('hub_connections', function (Blueprint $table) {
            $table->dropColumn('caller_ip');
        });
    }
};
