<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_sso_tokens', function (Blueprint $table) {
            $table->id();
            // The opaque short-lived token that goes in the URL handed to the browser.
            $table->string('token', 64)->unique()->index();
            // Which user's project this token is for (for audit purposes).
            $username = $table->string('username');
            // useAppSsoToken() matches this against the raw {username} route param;
            // MySQL's utf8mb4_unicode_ci already made that comparison ci.
            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                $username->collation('NOCASE');
            }
            // The cookie to set when the token is redeemed.
            $table->string('cookie_name');
            $table->string('cookie_value');
            // Where to redirect after setting the cookie.
            $table->string('redirect');
            // Hard expiry (set at creation, checked on redemption).
            $table->timestamp('expires_at');
            // Filled on first use; subsequent requests get a 404.
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_sso_tokens');
    }
};
