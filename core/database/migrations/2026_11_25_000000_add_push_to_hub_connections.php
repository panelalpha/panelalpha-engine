<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A push connection reports to the Hub instead of being called: its protocol, the previous Hub secret while login
 * tickets signed with it may still arrive, what the last answer asked for, the stored host facts the report sends,
 * every signed exchange, and the login tickets already used.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hub_connections', function (Blueprint $table) {
            $table->string('protocol', 8)->default('pull');
            $table->string('tls_spki', 64)->nullable()->change();
            $table->text('hub_secret_previous')->nullable();
            $table->dateTime('hub_secret_previous_until')->nullable();
            $table->unsignedSmallInteger('report_interval_seconds')->default(60);
            $table->json('report_want')->nullable();
            $table->dateTime('report_not_before')->nullable();
            $table->dateTime('last_report_at')->nullable();
            $table->string('last_sent_at', 32)->nullable();
            $table->boolean('rotate_secret')->default(false);
            $table->dateTime('upgrade_attempted_at')->nullable();
            $table->json('host_facts')->nullable();
            $table->dateTime('host_facts_at')->nullable();
            $table->json('last_backfill')->nullable();
        });

        Schema::create('hub_exchanges', function (Blueprint $table) {
            $table->id();
            $table->dateTime('at')->index();
            $table->string('kind', 16);
            $table->unsignedSmallInteger('status');
            $table->unsignedInteger('duration_ms');
            $table->unsignedInteger('bytes');
            $table->string('detail', 500)->nullable();
        });

        Schema::create('hub_sso_logins', function (Blueprint $table) {
            $table->id();
            $table->string('jti', 128)->unique();
            $table->string('app');
            $table->string('app_user')->nullable();
            $table->string('sub', 64)->nullable();
            $table->dateTime('at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hub_sso_logins');
        Schema::dropIfExists('hub_exchanges');
        Schema::table('hub_connections', function (Blueprint $table) {
            $table->dropColumn([
                'protocol', 'hub_secret_previous', 'hub_secret_previous_until', 'report_interval_seconds', 'report_want',
                'report_not_before', 'last_report_at', 'last_sent_at', 'rotate_secret', 'upgrade_attempted_at',
                'host_facts', 'host_facts_at', 'last_backfill',
            ]);
        });
    }
};
