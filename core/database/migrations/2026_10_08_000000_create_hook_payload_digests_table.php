<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every signed push body a hook has acted on. Kept apart from
        // hook_deliveries, whose history is pruned to a window: a replay must
        // stay a replay after the delivery it copies has aged out.
        Schema::create('hook_payload_digests', function (Blueprint $table) {
            $table->unsignedBigInteger('deploy_hook_id');
            $table->char('digest', 64);
            $table->timestamp('created_at')->nullable();

            $table->primary(['deploy_hook_id', 'digest']);
            $table->foreign('deploy_hook_id')
                ->references('id')
                ->on('deploy_hooks')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hook_payload_digests');
    }
};
