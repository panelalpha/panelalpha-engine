<?php

namespace App\Lib\Project;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\Platform\AppConfig\AppConfigLocator;
use App\Lib\Deploy\Platform\AppConfig\LocalAppConfigSource;
use App\Lib\Deploy\Platform\Strategies;
use App\Models\Tunnel;
use App\Models\User;
use App\System;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One project in a few normalized words: what it is, whether it is up, where
 * it answers and whether an admin can be logged in. Built for a list, so it
 * reads only the database (once for the whole list, {@see ProjectListFacts}),
 * the deploy lock and one `docker ps` per request -- never anything inside an account.
 */
final class ProjectOverview
{
    public const STATUS_LIVE = 'live';

    public const STATUS_BUILDING = 'building';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DOWN = 'down';

    public const STATUS_SUSPENDED = 'suspended';

    /** Created, never deployed. */
    public const STATUS_NONE = 'none';

    /** The same `docker ps` answers a whole list; a worker must not keep it forever. */
    private const RUNNING_TTL_SECONDS = 5;

    /** @var ?array{at: float, names: ?array<string, true>} */
    private static ?array $running = null;

    /**
     * @param  ?ProjectListFacts  $facts  loaded for the whole list the project is in; loaded for it alone when null
     * @return array{type: ?string, framework: ?string, label: ?string, status: string, public_url: ?string, admin_sso: bool}
     */
    public static function for(User $user, ?ProjectListFacts $facts = null): array
    {
        $facts ??= ProjectListFacts::load([$user]);
        $details = $user->getDetails();
        $strategy = is_string($details['deploy_strategy'] ?? null) ? $details['deploy_strategy'] : null;

        return [
            'type' => self::type($details, $user->getGitRepo()),
            'framework' => $strategy,
            'label' => is_string($details['deploy_label'] ?? null) ? $details['deploy_label'] : null,
            'status' => self::status($user, $details, $facts),
            'public_url' => self::publicUrl($user, $facts),
            'admin_sso' => self::adminSso($user, $details),
        ];
    }

    /**
     * wordpress, laravel, php, node, python, ruby, go, rust, java, dotnet,
     * docker, static or other; null before the first deploy.
     *
     * @param  array<string, mixed>  $details
     */
    public static function type(array $details, ?string $gitRepo = null): ?string
    {
        $strategy = $details['deploy_strategy'] ?? null;
        if (! is_string($strategy) || $strategy === '') {
            return null;
        }
        $label = is_string($details['deploy_label'] ?? null) ? $details['deploy_label'] : '';

        if (stripos($label, 'wordpress') !== false || ($gitRepo !== null && stripos($gitRepo, 'wordpress/wordpress') !== false)) {
            return 'wordpress';
        }

        return match (true) {
            $strategy === Strategies::LARAVEL => 'laravel',
            $strategy === Strategies::PHP => 'php',
            Strategies::isJsFramework($strategy) => 'node',
            in_array($strategy, [Strategies::PYTHON, Strategies::DJANGO], true) => 'python',
            in_array($strategy, [Strategies::RUBY, Strategies::RAILS], true) => 'ruby',
            in_array($strategy, [Strategies::GO, Strategies::RUST, Strategies::JAVA, Strategies::DOTNET], true) => $strategy,
            in_array($strategy, [Strategies::COMPOSE, Strategies::DOCKERFILE, Strategies::PAEMD], true) => 'docker',
            $strategy === Strategies::STATIC => 'static',
            default => 'other',
        };
    }

    /**
     * A deployed project is down only when `docker ps` was read and lacks its
     * container: an unreadable `docker ps` proves nothing either way.
     *
     * @param  array<string, mixed>  $details
     */
    public static function status(User $user, array $details, ?ProjectListFacts $facts = null): string
    {
        if ($user->status === 'suspended') {
            return self::STATUS_SUSPENDED;
        }
        if (self::isDeploying($user, $facts ?? ProjectListFacts::load([$user]))) {
            return self::STATUS_BUILDING;
        }

        $last = self::lastDeployStatus($user->username) ?? ($details['deployment_status'] ?? null);
        if ($last === DeployLogger::STATUS_FAILED) {
            return self::STATUS_FAILED;
        }
        if ($last === null && ! isset($details['deploy_strategy'])) {
            return self::STATUS_NONE;
        }

        $running = self::runningContainers();

        return $running !== null && ! isset($running[$user->username])
            ? self::STATUS_DOWN
            : self::STATUS_LIVE;
    }

    /** A tunnel is reachable from anywhere; a bare local domain may not be. */
    public static function publicUrl(User $user, ?ProjectListFacts $facts = null): ?string
    {
        if (! $user->exists) {
            return null;
        }
        $facts ??= ProjectListFacts::load([$user]);
        $tunnels = $facts->tunnelsOf($user);
        $tunnel = $tunnels->first(fn (Tunnel $t): bool => $t->domain?->domain === $user->domain) ?? $tunnels->first();
        if ($tunnel !== null) {
            return 'https://'.$tunnel->hostname;
        }

        if (! is_string($user->domain) || $user->domain === '') {
            return null;
        }
        $domain = $facts->mainDomainOf($user);

        return ($domain !== null && ! $domain->sslEnabled() ? 'http://' : 'https://').$user->domain;
    }

    /**
     * Frozen at deploy. A project deployed before that was recorded falls back
     * to the engine's own recipe for its repository.
     *
     * @param  array<string, mixed>  $details
     */
    public static function adminSso(User $user, array $details): bool
    {
        if (is_bool($details['deploy_admin_sso'] ?? null)) {
            return $details['deploy_admin_sso'];
        }

        $gitRepo = $user->getGitRepo();
        if ($gitRepo === null) {
            return false;
        }

        return AppConfigLocator::findShipped(new LocalAppConfigSource, $gitRepo)?->supportsAdminSso() ?? false;
    }

    /**
     * Tests: account containers to treat as running, or null for "could not
     * tell". Pass false to probe again.
     *
     * @param  array<int, string>|null|false  $names
     */
    public static function fakeRunning(array|null|false $names): void
    {
        self::$running = $names === false
            ? null
            : ['at' => INF, 'names' => $names === null ? null : array_fill_keys($names, true)];
    }

    private static function isDeploying(User $user, ProjectListFacts $facts): bool
    {
        return DeployLogger::isLockedFor($user->username) || $facts->hasQueuedDeploy($user);
    }

    /** The status of the deploy latest.json names; one read of it, not one per question. */
    private static function lastDeployStatus(string $username): ?string
    {
        try {
            $latest = DeployLogger::readLatestFor($username);
        } catch (Throwable) {
            return null;
        }
        $id = $latest['id'] ?? null;
        $status = is_string($id) && $id !== '' ? ($latest['status'] ?? null) : null;

        return is_string($status) ? $status : null;
    }

    /**
     * Every account's outer container is named after the account.
     *
     * @return ?array<string, true>
     */
    private static function runningContainers(): ?array
    {
        $now = microtime(true);
        if (self::$running !== null && $now - self::$running['at'] < self::RUNNING_TTL_SECONDS) {
            return self::$running['names'];
        }

        $names = null;
        try {
            $out = (new System)->exec(['sudo', 'docker', 'ps', '--format', '{{.Names}}'], [], 15);
            $names = array_fill_keys(array_filter(array_map('trim', explode("\n", $out))), true);
        } catch (Throwable $e) {
            Log::warning('Could not list running containers: '.$e->getMessage());
        }
        self::$running = ['at' => $now, 'names' => $names];

        return $names;
    }
}
