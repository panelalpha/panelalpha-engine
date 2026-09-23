<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deploy_hooks', function (Blueprint $table) {
            // The URL this hook's owner was actually given, frozen at create
            // or rotate time. `url()` is computed from the engine's *current*
            // address, so once that address changes (an IP swapped for a
            // domain certificate) the two drift apart -- and a git host still
            // calls the old one. Comparing them is how a client is told to
            // re-register.
            $table->string('registered_url')->nullable()->after('public_id');
        });

        // Best guess for rows that predate this column: the engine's current
        // address is the only one ever recorded, so it is what they were
        // registered under as far as this migration can know.
        $prefix = rtrim((string) config('app.url'), '/');
        DB::table('deploy_hooks')->whereNull('registered_url')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($prefix): void {
                foreach ($rows as $row) {
                    DB::table('deploy_hooks')->where('id', $row->id)->update([
                        'registered_url' => $prefix . '/hooks/' . $row->public_id,
                    ]);
                }
            });

        Schema::table('hook_deliveries', function (Blueprint $table) {
            // Set only for a `deployed`/`deploy_failed` result that actually
            // started a deploy (a Deploy-managed rebuild, not a Site Git
            // fast-forward) -- a pointer a client follows to the full build
            // output instead of the one-line `detail`.
            $table->string('deploy_id', 64)->nullable()->after('detail');
        });
    }

    public function down(): void
    {
        Schema::table('hook_deliveries', function (Blueprint $table) {
            $table->dropColumn('deploy_id');
        });
        Schema::table('deploy_hooks', function (Blueprint $table) {
            $table->dropColumn('registered_url');
        });
    }
};
