<?php

namespace App\Lib\Hub;

use App\Lib\Project\AppSsoLink;
use App\Models\Domain;
use App\Models\HubSsoLogin;
use App\Models\User;
use App\System\Project\Dind;
use Illuminate\Support\Facades\Log;

/**
 * Log the Hub user into an app as its administrator, the app user whose role comes first in ADMIN_ROLES; a user with
 * any other role is never picked.
 */
final class HubAdminLogin
{
    public const ADMIN_ROLES = ['role_super_admin', 'administrator', 'admin', 'role_admin', 'owner'];

    /**
     * Where a ticket posted to one of the project's own domains sends the browser, or null for every refusal alike.
     */
    public static function link(string $username, string $host, string $ticket): ?string
    {
        $project = User::findByUsername($username);
        if ($project === null || ! self::serves($project, $host)) {
            return null;
        }
        $claims = HubTicket::accept($ticket, $username);

        return $claims === null ? null : self::linkFor($project, $claims);
    }

    private static function serves(User $project, string $host): bool
    {
        $host = strtolower($host);
        foreach (Domain::query()->where('user_id', $project->id)->get() as $domain) {
            $names = array_map('strtolower', [(string) $domain->domain, ...$domain->getVhostAltNames()]);
            if (in_array($host, $names, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The app's one-time login link, or null when the app has no administrator or cannot make one.
     *
     * @param  array<string, mixed>  $claims  an accepted ticket's
     */
    private static function linkFor(User $project, array $claims): ?string
    {
        try {
            $runtime = $project->getTemplate() === 'dind' ? $project->project()->runtime() : null;
            if (! $runtime instanceof Dind) {
                return null;
            }
            $apps = $runtime->apps();
            $admin = self::pickAdmin($apps->listUsers());
            if ($admin === null) {
                return null;
            }
            $url = AppSsoLink::url((string) $project->username, $apps->ssoCredentials((string) $admin['id']));
        } catch (\Throwable $e) {
            Log::warning("Hub admin login into {$project->username} failed: ".$e->getMessage());

            return null;
        }

        HubSsoLogin::query()->where('jti', $claims['jti'])->update(['app_user' => mb_substr((string) $admin['username'], 0, 255)]);
        HubAudit::log('hub.sso', ['app' => $project->username, 'app_user' => $admin['username'], 'sub' => $claims['sub'] ?? null]);

        return $url;
    }

    /**
     * @param  array<int, mixed>  $users
     * @return ?array{id: string, username: string}
     */
    public static function pickAdmin(array $users): ?array
    {
        $best = null;
        $bestRank = count(self::ADMIN_ROLES);
        foreach ($users as $user) {
            if (! is_array($user) || ! is_scalar($user['id'] ?? null) || ! is_string($user['role'] ?? null)) {
                continue;
            }
            $rank = array_search(strtolower($user['role']), self::ADMIN_ROLES, true);
            if ($rank !== false && $rank < $bestRank) {
                $best = ['id' => (string) $user['id'], 'username' => (string) ($user['username'] ?? $user['id'])];
                $bestRank = $rank;
            }
        }

        return $best;
    }
}
