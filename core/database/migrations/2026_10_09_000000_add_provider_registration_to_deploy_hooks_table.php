<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The provider a hook was registered at, its id for the webhook and when
     * it was made. `provider` is stored rather than re-read from the
     * repository URL, which alone does not name a self-managed host.
     */
    public function up(): void
    {
        Schema::table('deploy_hooks', function (Blueprint $table) {
            $table->string('provider', 32)->nullable();
            $table->string('provider_hook_id', 255)->nullable();
            $table->timestamp('registered_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('deploy_hooks', function (Blueprint $table) {
            $table->dropColumn(['provider', 'provider_hook_id', 'registered_at']);
        });
    }
};
