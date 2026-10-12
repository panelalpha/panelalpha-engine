<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddProjectToMcpActivityLogsTable extends Migration
{
    public function up(): void
    {
        Schema::table('mcp_activity_logs', function (Blueprint $table) {
            $table->string('project')->nullable()->index()->after('token_name');
        });
    }

    public function down(): void
    {
        Schema::table('mcp_activity_logs', function (Blueprint $table) {
            $table->dropIndex(['project']);
            $table->dropColumn('project');
        });
    }
}
