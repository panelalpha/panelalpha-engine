<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operator_images', function (Blueprint $table) {
            $table->id();
            $table->string('ref')->unique();
            $table->string('source', 16);
            $table->text('dockerfile')->nullable();
            $table->json('build_args')->nullable();
            $table->string('why')->nullable();
            $table->string('status', 16);
            $table->string('digest')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->timestamp('refreshed_at')->nullable();
            $table->text('error')->nullable();
            $table->unsignedBigInteger('task_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_images');
    }
};
