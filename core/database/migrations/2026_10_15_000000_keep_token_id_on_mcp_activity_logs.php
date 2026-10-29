<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deleting a token used to null `token_id` on every row it wrote, so a panel could no
 * longer list a user's calls by the ids of the tokens that user once held. The foreign
 * key goes; the index stays, and a deleted token's rows keep its id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mcp_activity_logs', function (Blueprint $table) {
            $table->dropForeign(['token_id']);
        });
    }

    public function down(): void
    {
        DB::table('mcp_activity_logs')
            ->whereNotNull('token_id')
            ->whereNotIn('token_id', DB::table('personal_access_tokens')->select('id'))
            ->update(['token_id' => null]);

        Schema::table('mcp_activity_logs', function (Blueprint $table) {
            $table->foreign('token_id')->references('id')->on('personal_access_tokens')->onDelete('set null');
        });
    }
};
