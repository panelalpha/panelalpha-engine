<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sftp_accounts', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id')->index();
            $username = $table->string('username');
            // Matches SftpAccountController's lookups on this column, which are not
            // pre-lowercased; MySQL's utf8mb4_unicode_ci already made them ci.
            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                $username->collation('NOCASE');
            }
            $table->string('auth_method');
            $table->text('password')->nullable();
            $table->text('public_key')->nullable();
            $table->json('details')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sftp_accounts');
    }
};
