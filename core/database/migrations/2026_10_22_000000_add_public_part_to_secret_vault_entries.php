<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A deploy key the vault generated has a public half a caller must read to
 * add it to a repository; a pasted secret has none.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('secret_vault_entries') || Schema::hasColumn('secret_vault_entries', 'public_part')) {
            return;
        }

        Schema::table('secret_vault_entries', function (Blueprint $table) {
            $table->json('public_part')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('secret_vault_entries', 'public_part')) {
            Schema::table('secret_vault_entries', function (Blueprint $table) {
                $table->dropColumn('public_part');
            });
        }
    }
};
