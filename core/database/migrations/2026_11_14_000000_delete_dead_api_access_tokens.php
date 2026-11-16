<?php

use App\Models\Admin;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * An engine before 2.3.0 revoked a project's API token by stamping `revoked_at`, and a deleted project left its
 * tokens behind: no such row is listed or accepted again, so they go.
 */
return new class extends Migration
{
    private const TOKEN_NAME = 'api-access:';

    public function up(): void
    {
        $dead = $this->deadTokenIds();
        if ($dead !== []) {
            DB::table('personal_access_tokens')->whereIn('id', $dead)->delete();
        }
    }

    public function down(): void {}

    /**
     * The engine's own project tokens that are revoked, or whose project is gone.
     *
     * @return list<int>
     */
    private function deadTokenIds(): array
    {
        $root = DB::table('admins')->where('name', 'root')->value('id');
        if ($root === null) {
            return [];
        }

        return DB::table('personal_access_tokens')
            ->where('tokenable_type', Admin::class)
            ->where('tokenable_id', $root)
            ->where('name', 'like', self::TOKEN_NAME.'%')
            ->orderBy('id')
            ->get(['id', 'name', 'revoked_at'])
            ->filter(fn (object $token): bool => $token->revoked_at !== null || ! $this->projectExists((string) $token->name))
            ->map(fn (object $token): int => (int) $token->id)
            ->values()
            ->all();
    }

    private function projectExists(string $tokenName): bool
    {
        return DB::table('users')->where('username', substr($tokenName, strlen(self::TOKEN_NAME)))->exists();
    }
};
