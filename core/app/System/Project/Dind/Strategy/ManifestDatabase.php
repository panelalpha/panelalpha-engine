<?php

namespace App\System\Project\Dind\Strategy;

use App\Lib\Deploy\Platform\Runtime\Php\DatabaseSettings;
use App\Lib\Deploy\Platform\Strategies;
use App\Lib\Deploy\Sidecar\SidecarEngine;
use App\System\Project\Dind as DindProject;
use App\System\Project\Dind\AppDatabase;

/**
 * A manifest's `database: mysql`, for the writers that generate their own app
 * service outside the PHP strategy: a repository Dockerfile (Kimai) and the
 * generated framework builds.
 *
 * Same database as {@see PhpStrategy} provisions -- {@see AppDatabase} on the
 * account's own MySQL server -- handed over as DATABASE_URL plus DB_*, with
 * the host pinned into `extra_hosts` because the nested Docker resolves none
 * of the engine's names.
 */
class ManifestDatabase
{
    private const MYSQL = 'mysql';

    public function __construct(private DindProject $dind)
    {
    }

    /**
     * What to add to the generated app service: `env` and `extra_hosts`, or
     * nothing when no database was declared or the project ships a MySQL of
     * its own (that one is already wired to the app, and stays).
     *
     * @param array<string, mixed> $decision
     * @param array<string, mixed> $services harvested sidecar services
     * @return array{env?: array<string, string>, extra_hosts?: list<string>}
     */
    public function forService(array $decision, array $services): array
    {
        if (!self::isDeclared($decision) || SidecarEngine::servicesProvide($services, self::MYSQL)) {
            return [];
        }

        $db = $this->provision();
        $this->dind->shell()->logger()?->info(
            "Using the account's MySQL database {$db['database']} for the recipe's `database: mysql`"
        );
        $layer = ['env' => self::environment($db)];
        $extraHosts = $this->extraHosts();

        return $extraHosts === [] ? $layer : $layer + ['extra_hosts' => $extraHosts];
    }

    /** @param array<string, mixed> $decision */
    public static function isDeclared(array $decision): bool
    {
        return ($decision['database'] ?? null) === self::MYSQL;
    }

    /**
     * Whether the writer the strategy dispatches to provisions the database.
     * Mirrors {@see \App\System\Project\Dind\DeployStrategy::apply()}.
     */
    public static function isHonouredBy(string $strategy): bool
    {
        if (in_array($strategy, [Strategies::PHP, Strategies::LARAVEL, Strategies::DOCKERFILE], true)) {
            return true;
        }

        return !in_array($strategy, [Strategies::RAILS, Strategies::RUBY], true) && Strategies::isGenerated($strategy);
    }

    /**
     * Deploy-log warning for a declared database no writer will provision:
     * the project's own compose file, Rails/Ruby, Railpack, static, fallback.
     *
     * @param array<string, mixed> $decision
     */
    public static function inertWarning(array $decision): ?string
    {
        $strategy = is_string($decision['strategy'] ?? null) ? $decision['strategy'] : '';
        if (!is_string($decision['database'] ?? null) || $decision['database'] === '' || self::isHonouredBy($strategy)) {
            return null;
        }

        return "The recipe declares `database: {$decision['database']}`, but strategy {$strategy} does not provision one;"
            . ' this deploy gets no database and no DB_* variables.';
    }

    /**
     * DATABASE_URL (Doctrine, Kimai's entrypoint, Rails, Prisma) plus the
     * Laravel-style DB_* spellings. Percent-encoded, so a password with `@`
     * or `/` cannot leak into the host or the path.
     *
     * @param array<string, string> $db {@see AppDatabase::provision()}
     * @return array<string, string>
     */
    public static function environment(array $db): array
    {
        $settings = DatabaseSettings::fromArray($db);

        return [
            'DATABASE_URL' => sprintf(
                '%s://%s:%s@%s:%s/%s?charset=utf8mb4',
                $settings->driver(),
                rawurlencode($settings->username()),
                rawurlencode($settings->password()),
                $settings->host(),
                $settings->port(),
                rawurlencode($settings->database())
            ),
            'DB_CONNECTION' => $settings->driver(),
            'DB_HOST' => $settings->host(),
            'DB_PORT' => $settings->port(),
            'DB_DATABASE' => $settings->database(),
            'DB_USERNAME' => $settings->username(),
            'DB_PASSWORD' => $settings->password(),
        ];
    }

    /** @return array{connection: string, host: string, port: string, database: string, username: string, password: string} */
    protected function provision(): array
    {
        return AppDatabase::provision($this->dind->userModel());
    }

    /** @return list<string> */
    protected function extraHosts(): array
    {
        return AppDatabase::extraHosts($this->dind);
    }
}
