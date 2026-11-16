<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * POST /uploads. The file waits under storage/app/uploads until a create or
 * source attach claims it, moves it into the project and deletes the row.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('uploads')) {
            return;
        }

        Schema::create('uploads', static function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('filename', 255);
            $table->unsignedBigInteger('size');
            $table->string('path', 1024);
            $table->string('claimed_by', 64)->nullable();
            $table->unsignedBigInteger('created_by_token_id')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uploads');
    }
};
