<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $username = $table->string('username')->unique();
            // MySQL's utf8mb4_unicode_ci makes username lookups case-insensitive; match that on sqlite.
            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                $username->collation('NOCASE');
            }
            $table->string('domain')->unique();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->json('details')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('users');
    }
};
