<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * The random key of a `vault:<ref_key>` reference. Every existing entry gets one too,
     * so its old `vault:<id>` stops naming it.
     */
    public function up(): void
    {
        Schema::table('secret_vault_entries', function (Blueprint $table) {
            $table->string('ref_key', 64)->nullable()->unique();
        });

        foreach (DB::table('secret_vault_entries')->whereNull('ref_key')->pluck('id') as $id) {
            DB::table('secret_vault_entries')->where('id', $id)->update(['ref_key' => Str::random(24)]);
        }
    }

    public function down(): void
    {
        Schema::table('secret_vault_entries', function (Blueprint $table) {
            $table->dropUnique(['ref_key']);
            $table->dropColumn('ref_key');
        });
    }
};
