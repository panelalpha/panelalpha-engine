<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('secret_vault_entries', function (Blueprint $table) {
            // What a paste is checked against, given at mint (engine#7), and
            // the outcome of that check once a secret is stored.
            $table->json('verify_with')->nullable()->after('purpose');
            $table->json('verification')->nullable()->after('verify_with');
        });
    }

    public function down(): void
    {
        Schema::table('secret_vault_entries', function (Blueprint $table) {
            $table->dropColumn(['verify_with', 'verification']);
        });
    }
};
