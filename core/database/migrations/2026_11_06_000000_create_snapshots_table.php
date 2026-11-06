<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('username')->index();
            $table->string('kind');
            $table->string('deploy_id')->nullable();
            $table->string('status');
            $table->string('restore_status')->nullable();
            $table->json('databases')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'kind', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('snapshots');
    }
};
