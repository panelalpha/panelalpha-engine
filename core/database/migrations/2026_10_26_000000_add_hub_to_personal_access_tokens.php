<?php

use App\Lib\Hub\HubGrants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marks PanelAlpha Connect's tokens, whose `hub:` name any token creator can
 * pick too. A token already issued to the Hub is told by that name together
 * with abilities limited to routes a Hub grant gives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->boolean('hub')->default(false)->after('abilities');
        });

        DB::table('personal_access_tokens')->whereIn('id', $this->issuedHubTokenIds())->update(['hub' => true]);
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn('hub');
        });
    }

    /** @return list<int> */
    private function issuedHubTokenIds(): array
    {
        return DB::table('personal_access_tokens')
            ->where('name', 'like', 'hub:%')
            ->get(['id', 'abilities'])
            ->filter(fn (object $token): bool => HubGrants::isHubScoped((array) json_decode((string) $token->abilities, true)))
            ->map(fn (object $token): int => (int) $token->id)
            ->values()
            ->all();
    }
};
