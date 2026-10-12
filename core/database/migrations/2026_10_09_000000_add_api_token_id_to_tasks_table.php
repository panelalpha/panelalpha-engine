<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('api_token_id')->nullable()->after('id');
            $table->index(['api_token_id', 'id']);
            $table->index(['username', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['api_token_id', 'id']);
            $table->dropIndex(['username', 'status']);
            $table->dropColumn('api_token_id');
        });
    }
};
