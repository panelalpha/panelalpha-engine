<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('secret_vault_entries', function (Blueprint $table) {
            // `request` (the original behaviour: a slot referenced by
            // `vault:<ref>`, gone when it expires) or `global` (the engine's
            // own credential of that type, reused by every project that does
            // not carry one of its own). Indexed with the type because every
            // global lookup is `WHERE scope = 'global' AND type = ?`.
            $table->string('scope', 16)->default('request')->after('type');
            // When the paste *form* stops accepting. Always set, an hour from
            // the mint, for both scopes -- a global secret outlives its link
            // rather than leaving a standing capability to overwrite itself.
            $table->timestamp('link_expires_at')->nullable()->after('filled_at');
            $table->index(['scope', 'type']);
        });

        // The secret's own life, which is now separate from the link's: null
        // means never, and that is what a global credential is.
        Schema::table('secret_vault_entries', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->change();
        });

        // Every existing row is request-scoped and its one column was both
        // clocks; splitting it leaves them behaving exactly as they did.
        DB::table('secret_vault_entries')->update(['link_expires_at' => DB::raw('expires_at')]);
    }

    public function down(): void
    {
        // Global entries have no expiry to restore, and the column is about to
        // stop being nullable.
        DB::table('secret_vault_entries')->whereNull('expires_at')->delete();

        Schema::table('secret_vault_entries', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable(false)->change();
            $table->dropIndex(['scope', 'type']);
            $table->dropColumn(['scope', 'link_expires_at']);
        });
    }
};
