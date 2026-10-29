<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PanelAlpha Connect ("the Hub"): the connection this engine holds and the
 * calls the Hub made with its token.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hub_connections', function (Blueprint $table) {
            $table->id();
            $table->string('connection_id')->unique();
            $table->string('hub_url');
            // Encrypted with the app key; never printed.
            $table->text('hub_secret')->nullable();
            $table->string('account_id');
            $table->string('account_name')->nullable();
            $table->string('approved_by')->nullable();
            // As approved, for display. The Hub can attach or detach a licence later.
            $table->string('license_id')->nullable();
            $table->string('license_name')->nullable();
            $table->string('ip', 45);
            $table->string('tls_spki', 64);
            $table->json('grants')->nullable();
            $table->unsignedBigInteger('token_id')->nullable();
            $table->string('status', 16)->index();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_hub_call_at')->nullable();
            $table->timestamps();
        });

        Schema::create('hub_access_log', function (Blueprint $table) {
            $table->id();
            $table->timestamp('at')->index();
            $table->string('method', 8);
            $table->string('route');
            $table->string('app')->nullable();
            $table->unsignedSmallInteger('status');
            $table->string('source_ip', 45)->nullable();
            $table->unsignedBigInteger('token_id')->nullable();
            $table->string('detail')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hub_access_log');
        Schema::dropIfExists('hub_connections');
    }
};
