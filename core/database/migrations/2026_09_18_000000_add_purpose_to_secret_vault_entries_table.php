<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('secret_vault_entries', function (Blueprint $table) {
            // What this secret is for, in the words of whoever asked for it:
            // "deploy key for the shop repo", "Cloudflare token for the
            // staging zone". The type says what *kind* of secret it is and
            // several entries share one; this is what tells them apart in a
            // listing, which is the only way to decide which to delete when
            // the secret itself can never be read back.
            $table->string('purpose', 255)->nullable()->after('scope');
        });
    }

    public function down(): void
    {
        Schema::table('secret_vault_entries', function (Blueprint $table) {
            $table->dropColumn('purpose');
        });
    }
};
