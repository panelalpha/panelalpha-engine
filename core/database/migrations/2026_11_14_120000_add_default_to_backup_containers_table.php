<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The store a panel uses without being configured. A host that already has
 * stores keeps them and keeps the oldest as the default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_containers', function (Blueprint $table) {
            $table->boolean('is_default')->default(false);
        });

        $oldest = DB::table('backup_containers')->orderBy('id')->first();
        if ($oldest !== null) {
            DB::table('backup_containers')->where('id', $oldest->id)->update(['is_default' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('backup_containers', function (Blueprint $table) {
            $table->dropColumn('is_default');
        });
    }
};
