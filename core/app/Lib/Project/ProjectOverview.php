<?php

namespace App\Lib\Project;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\Platform\AppConfig\AppConfigLocator;
use App\Lib\Deploy\Platform\AppConfig\LocalAppConfigSource;
use App\Lib\Deploy\Platform\Strategies;
use App\Lib\Domains\HttpsPorts;
use App\Models\Tunnel;
use App\Models\User;
use App\System\Projects;

/**
 * One project in a few words: what it is, whether it is up, where it answers, whether an admin can be logged in. Cheap
 * for a list: {@see ProjectListFacts} once for all of it, nothing asked of the host or an account.
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

    /**
     * @param  ?ProjectListFacts  $facts  loaded for the whole list the project is in; loaded for it alone when null
     * @return array{type: ?string, path: ?string, framework: ?string, label: ?string, status: string, public_url: ?string, admin_sso: bool}
     */
    public static function for(User $user, ?ProjectListFacts $facts = null): array
    {
        $facts ??= ProjectListFacts::load([$user]);
        $details = $user->getDetails();
        $strategy = is_string($details['deploy_strategy'] ?? null) ? $details['deploy_strategy'] : null;
        $type = self::type($details, $user->getGitRepo()) ?? self::classicType($user);

        return [
            'type' => $type,
            'path' => self::installPath($user, $type),
            'framework' => $strategy,
            'label' => is_string($details['deploy_label'] ?? null) ? $details['deploy_label'] : null,
            'status' => self::status($user, $details, $facts),
            'public_url' => self::publicUrl($user, $facts),
            'admin_sso' => self::adminSso($user, $details),
        ];
    }

    /**
     * A container project's type: wordpress, laravel, php, node, python, ruby,
     * go, rust, java, dotnet, docker, static or other; null before the first deploy.
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

    /** What a PHP hosting project's files were last read as; a container project not deployed yet has no type. */
    private static function classicType(User $user): ?string
    {
        return Projects::isDind($user) ? null : ClassicAppType::of($user);
    }

    /**
     * Where WordPress is when it is not the main domain's document root; the documented rule needs no path.
     *
     * @param  ?string  $type  what the project was already reported as
     */
    private static function installPath(User $user, ?string $type): ?string
    {
        return $type === ClassicAppType::WORDPRESS && ! Projects::isDind($user)
            ? ClassicAppType::installPathOf($user)
            : null;
    }

    /**
     * A deployed project is down only when a sample that saw every running container did not see its own: without
     * one nothing is known either way.
     *
     * @param  array<string, mixed>  $details
     */
    public static function status(User $user, array $details, ?ProjectListFacts $facts = null): string
    {
        $facts ??= ProjectListFacts::load([$user]);
        if ($user->status === 'suspended') {
            return self::STATUS_SUSPENDED;
        }
        if ($facts->isDeploying($user)) {
            return self::STATUS_BUILDING;
        }

        $last = $facts->lastDeployStatus($user) ?? ($details['deployment_status'] ?? null);
        if ($last === DeployLogger::STATUS_FAILED) {
            return self::STATUS_FAILED;
        }
        if ($last === null && ! isset($details['deploy_strategy'])) {
            return self::STATUS_NONE;
        }

        return $facts->isRunning($user) === false ? self::STATUS_DOWN : self::STATUS_LIVE;
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
        if ($domain !== null && ! $domain->sslEnabled()) {
            return 'http://'.$user->domain;
        }

        return 'https://'.$user->domain.($domain !== null ? HttpsPorts::suffix($domain) : '');
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
}
