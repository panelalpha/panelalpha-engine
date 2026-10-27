<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_token_rotations', function (Blueprint $table) {
            $table->timestamp('from_expires_at')->nullable()->after('to_token_id');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_token_rotations', function (Blueprint $table) {
            $table->dropColumn('from_expires_at');
        });
    }
};
