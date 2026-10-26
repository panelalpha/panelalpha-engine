<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('secret_vault_entries', function (Blueprint $table) {
            $table->id();
            // The one string that is both the form URL token and the `vault:`
            // reference an API caller passes. Stored hashed: a DB dump yields
            // no live form links, and lookup is `WHERE ref_hash = sha256(input)`
            // whichever side it arrives from.
            $table->char('ref_hash', 64)->unique()->index();
            // The request field the ref was created for -- `git_token`,
            // `env_vars`. The DB lookup enforces it (`WHERE ref_hash = ? AND
            // type = ?`), so an entry created for one field cannot be spent
            // as another.
            $table->string('type');
            // The secret, encrypted, from the moment the form pasted it.
            $table->text('secret_encrypted')->nullable();
            // Set when the form was submitted; null means still `pending`.
            $table->timestamp('filled_at')->nullable();
            // One clock for everything: the paste window and every read of
            // the entry both end here. Reusable until then.
            $table->timestamp('expires_at');
            // Audit only.
            $table->unsignedInteger('use_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('secret_vault_entries');
    }
};
