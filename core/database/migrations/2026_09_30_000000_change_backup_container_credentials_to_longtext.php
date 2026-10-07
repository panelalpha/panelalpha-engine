<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Credentials are stored encrypted, and ciphertext is not JSON. A MySQL/MariaDB
 * install that created backup_containers while the column was still json()
 * refuses it (MariaDB adds a json_valid check). MODIFY drops that check; on a table
 * that is already LONGTEXT it changes nothing. SQLite never checked.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement('ALTER TABLE backup_containers MODIFY credentials LONGTEXT NULL');
    }

    public function down(): void
    {
        // Going back to json() would reject the encrypted rows already stored.
    }
};
