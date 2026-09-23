<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_containers', function (Blueprint $table) {
            $table->id();
            $name = $table->string('name')->unique();
            // MySQL's utf8mb4_unicode_ci makes name lookups case-insensitive; match that on sqlite.
            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                $name->collation('NOCASE');
            }
            $table->string('driver');
            $table->string('location', 1024);
            $table->longText('credentials')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_containers');
    }
};
