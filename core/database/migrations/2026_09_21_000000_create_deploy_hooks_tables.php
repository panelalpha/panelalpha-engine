<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deploy_hooks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            // Which checkout of the project the hook redeploys: the key
            // Git::pathKey() gives (`project` for the Deploy-managed one).
            $table->string('path_key');
            // The random, opaque segment of the hook's URL. The URL is the
            // address, the signature is the credential: this is deliberately
            // not derived from the username, so a URL says nothing about
            // whose project it reaches. Stored as-is because showing the
            // hook later means printing it again.
            $table->string('public_id', 64)->unique();
            // The HMAC secret, encrypted. It is needed in the clear to check
            // a signature, so it cannot be hashed the way the vault's refs are.
            $table->text('secret_encrypted');
            $table->timestamps();

            $table->unique(['user_id', 'path_key']);
        });

        Schema::create('hook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('deploy_hook_id');
            $table->string('provider', 32);
            // The provider's own id for the delivery, which a redelivery
            // repeats. Only ever written for a delivery that proved who sent
            // it: a forged request must not be able to claim an id and turn
            // the genuine one into a "duplicate". NULLs do not collide.
            $table->string('delivery_id', 128)->nullable();
            $table->string('event', 64)->nullable();
            $table->string('branch')->nullable();
            $table->string('commit', 64)->nullable();
            // Fixed on arrival, in the request: queued, ignored, rejected.
            $table->string('outcome', 16);
            $table->string('reason')->nullable();
            // Filled in when the queued work ends: deployed, partial, deploy_failed, pull_refused, superseded.
            $table->string('result', 16)->nullable();
            $table->text('detail')->nullable();
            $table->timestamps();

            $table->unique(['deploy_hook_id', 'delivery_id']);
            $table->index(['deploy_hook_id', 'id']);
            $table->foreign('deploy_hook_id')
                ->references('id')
                ->on('deploy_hooks')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hook_deliveries');
        Schema::dropIfExists('deploy_hooks');
    }
};
