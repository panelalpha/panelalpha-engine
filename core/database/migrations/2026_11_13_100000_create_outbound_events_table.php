<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbound_events', function (Blueprint $table) {
            $table->id();
            $table->string('owner', 128);
            $table->string('network', 16);
            $table->string('kind', 8);
            $table->string('address', 45);
            $table->timestamp('started_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('until_at')->nullable();
            $table->timestamp('lifted_at')->nullable();
            $table->string('lifted_by', 64)->nullable();
            $table->timestamps();

            $table->unique(['owner', 'kind', 'started_at']);
            $table->index(['owner', 'started_at']);
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_events');
    }
};
