<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An SFTP login is unique on the host, and now the database says so. Concurrent creates could store one login more
 * than once, each row a logins.conf line with its own password. Of each such group the row with the lowest id stays:
 * the first created, and the one every lookup by name (update, delete, kill-sessions) has been acting on. The rest go.
 * Names are grouped by the column's own collation, the equality the unique index enforces.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->dropDuplicateLogins();

        Schema::table('sftp_accounts', function (Blueprint $table) {
            $table->unique('username');
        });
    }

    public function down(): void
    {
        Schema::table('sftp_accounts', function (Blueprint $table) {
            $table->dropUnique(['username']);
        });
    }

    private function dropDuplicateLogins(): void
    {
        $kept = DB::table('sftp_accounts')
            ->selectRaw('MIN(id) AS id')
            ->groupBy('username')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('id');

        foreach ($kept as $id) {
            $username = DB::table('sftp_accounts')->where('id', $id)->value('username');
            DB::table('sftp_accounts')->where('username', $username)->where('id', '!=', $id)->delete();
        }
    }
};
