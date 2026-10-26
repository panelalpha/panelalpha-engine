<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When a hook first received a signed delivery under its current secret.
     * From then on an unsigned one is refused, so a captured static token
     * cannot stand in for the signature.
     */
    public function up(): void
    {
        Schema::table('deploy_hooks', function (Blueprint $table) {
            $table->timestamp('signature_seen_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('deploy_hooks', function (Blueprint $table) {
            $table->dropColumn('signature_seen_at');
        });
    }
};
