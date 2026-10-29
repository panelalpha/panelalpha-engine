<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_token_rotations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('from_token_id')->unique();
            $table->unsignedBigInteger('to_token_id');
            // The new token in plain text, encrypted, for the grace period only.
            $table->text('plain_token');
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_token_rotations');
    }
};
