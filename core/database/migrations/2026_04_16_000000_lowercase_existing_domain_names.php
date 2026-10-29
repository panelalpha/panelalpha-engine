<?php

use App\Models\Domain;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        try {
            // Lowercase domain column in users table
            DB::statement('UPDATE users SET domain = LOWER(domain)');

            // Lowercase domain column in domains table
            DB::statement('UPDATE domains SET domain = LOWER(domain)');

            // Lowercase aliases stored in domains.details JSON
            Domain::whereNotNull('details')->get()->each(function (Domain $domain) {
                $details = $domain->details;
                if (! empty($details['aliases']) && is_array($details['aliases'])) {
                    $details['aliases'] = array_map('strtolower', $details['aliases']);
                    $domain->details = $details;
                    $domain->save();
                }
            });
        } catch (Exception $e) {
        }
    }

    public function down(): void
    {
        // Lowercasing is a one-way data normalization; no rollback possible.
    }
};
