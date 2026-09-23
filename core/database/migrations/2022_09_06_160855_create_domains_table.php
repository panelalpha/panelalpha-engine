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
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id')->index();
            $domain = $table->string('domain')->index();
            // MySQL's utf8mb4_unicode_ci makes domain lookups case-insensitive; match that on sqlite.
            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                $domain->collation('NOCASE');
            }
            $table->string('type');
            $table->json('details')->nullable();
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
        Schema::dropIfExists('domains');
    }
};
