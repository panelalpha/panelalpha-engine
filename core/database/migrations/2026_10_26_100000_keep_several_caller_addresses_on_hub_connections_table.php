<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hub_connections', function (Blueprint $table) {
            $table->json('caller_ips')->nullable()->after('ip');
        });

        $now = Carbon::now('UTC')->toIso8601ZuluString();
        foreach (DB::table('hub_connections')->whereNotNull('caller_ip')->get(['id', 'caller_ip']) as $row) {
            DB::table('hub_connections')->where('id', $row->id)->update(['caller_ips' => json_encode([$row->caller_ip => $now])]);
        }

        Schema::table('hub_connections', function (Blueprint $table) {
            $table->dropColumn('caller_ip');
        });
    }

    public function down(): void
    {
        Schema::table('hub_connections', function (Blueprint $table) {
            $table->string('caller_ip', 45)->nullable()->after('ip');
        });

        foreach (DB::table('hub_connections')->whereNotNull('caller_ips')->get(['id', 'caller_ips']) as $row) {
            $seen = json_decode((string) $row->caller_ips, true);
            if (is_array($seen) && $seen !== []) {
                arsort($seen, SORT_STRING);
                DB::table('hub_connections')->where('id', $row->id)->update(['caller_ip' => (string) array_key_first($seen)]);
            }
        }

        Schema::table('hub_connections', function (Blueprint $table) {
            $table->dropColumn('caller_ips');
        });
    }
};
