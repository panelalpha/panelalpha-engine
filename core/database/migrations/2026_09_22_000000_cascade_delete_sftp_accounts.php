<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Orphaned SFTP accounts outlived the projects they belonged to: Project::destroy()
 * never touched them and the column carried no foreign key. The rows that are
 * already orphaned go first, then the constraint that stops it happening again.
 *
 * `user_id` was declared `bigInteger` while `users.id` is an unsigned
 * `bigIncrements`, and MySQL refuses a foreign key across that mismatch, so the
 * column is widened to match before the constraint is added.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('sftp_accounts')
            ->whereNotIn('user_id', DB::table('users')->select('id'))
            ->delete();

        Schema::table('sftp_accounts', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->change();
        });

        Schema::table('sftp_accounts', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sftp_accounts', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });
    }
};
