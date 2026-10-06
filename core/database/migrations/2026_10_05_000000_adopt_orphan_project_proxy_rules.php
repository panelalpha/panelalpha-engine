<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Project rules from older panels carry no username, and a project's rule is now
 * served only to its own app. One whose upstream names a project becomes that
 * project's; any other is left as it is and logged.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rules = DB::table('proxy_rules')->where('owner_scope', 'user')->whereNull('username')->orderBy('id')->get();
        foreach ($rules as $rule) {
            $owner = DB::table('users')->where('username', $rule->upstream_host)->value('username');
            if ($owner === $rule->upstream_host) {
                DB::table('proxy_rules')->where('id', $rule->id)->update(['username' => $owner]);
                continue;
            }
            Log::warning("Proxy rule {$rule->id} has no project, and its upstream {$rule->upstream_host} names none: it is not served.");
        }
    }

    public function down(): void
    {
        // The rules now name the project they always pointed at.
    }
};
