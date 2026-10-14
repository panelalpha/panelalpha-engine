<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->string('kind')->default('manual')->after('container_id');
            $table->string('note')->nullable()->after('kind');
            $table->json('contents')->nullable()->after('note');

            $table->index(['user_id', 'kind', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'kind', 'created_at']);
            $table->dropColumn(['kind', 'note', 'contents']);
        });
    }
};
