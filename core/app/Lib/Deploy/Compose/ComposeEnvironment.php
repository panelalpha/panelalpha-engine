<?php

namespace App\Lib\Deploy\Compose;

/**
 * The environment a generated service runs with, in the order its layers
 * outrank one another.
 *
 * Compose gives `environment:` precedence over `env_file:`, so every variable
 * a strategy names here is one the account cannot correct in the `.env` it was
 * given — `env_vars` wrote `APP_ENV=prod` into the file and the container kept
 * running with the `APP_ENV=production` the PHP platform ships, which is
 * Laravel's value and the one Symfony refuses to boot on.
 *
 * Everything a strategy generates is therefore a default. The project's
 * app config may restate it, and what the account set outranks both — which is
 * what `env_vars` has claimed to do since it was added, and what the copy in
 * `.env` already did.
 *
 * Two groups stay the engine's own, because the engine reads them back and a
 * mismatch fails quietly. `PA_*` are the generated entrypoint's own
 * variables: `PA_DOCROOT` is the Apache document root, `PA_DEPLOY_PHASE`
 * decides install from upgrade. `HOST`, `HOSTNAME` and `PORT` name the port
 * compose publishes and the health check probes — an account that moved those
 * would get a container nothing can reach and a log that never says why.
 *
 * No Laravel dependencies — unit-testable.
 */
final class ComposeEnvironment
{
    /** The generated entrypoint's own variables. */
    public const RESERVED_PREFIX = 'PA_';

    /**
     * A stable secret per account, for an app that would otherwise derive one
     * from its install path: every account's app is at `/app`, so a salt or
     * session name taken from `__DIR__` is the same on every tenant (engine#175).
     */
    public const INSTANCE_SECRET = 'PA_INSTANCE_SECRET';

    /**
     * Where the application is published and probed.
     *
     * @var list<string>
     */
    public const RESERVED = ['HOST', 'HOSTNAME', 'PORT'];

    /**
     * Fold what the app config and the account declared onto a decision's `env`.
     *
     * @param array<string, mixed> $decision
     * @param array<string, string> $appConfig the project's `panelalpha.yaml`
     * @param array<string, string> $account the account's own `env_vars`
     * @return array<string, mixed>
     */
    public static function layer(array $decision, array $appConfig, array $account): array
    {
        $overrides = array_merge(
            self::overridable(ComposeValues::stringMap($appConfig)),
            // An empty field means "keep what the project shipped", not "set
            // this to nothing" — the rule ProjectEnvironment already applies
            // to the same values on their way into .env.
            self::overridable(array_filter(
                ComposeValues::stringMap($account),
                static fn (string $value): bool => $value !== ''
            ))
        );

        if ($overrides === []) {
            return $decision;
        }

        $decision['env'] = array_merge(
            ComposeValues::stringMap($decision['env'] ?? null),
            $overrides
        );

        return $decision;
    }

    /**
     * The decision with the engine's own per-account variables added. Reserved,
     * so neither the app config nor the account's env_vars can replace them.
     *
     * @param array<string, mixed> $decision
     * @return array<string, mixed>
     */
    public static function withInstanceSecret(array $decision, string $secret): array
    {
        $decision['env'] = array_merge(
            ComposeValues::stringMap($decision['env'] ?? null),
            [self::INSTANCE_SECRET => $secret]
        );

        return $decision;
    }

    /**
     * `${PA_PUBLIC_URL}` / `${PA_PUBLIC_HOST}` in the decision's env resolved,
     * as the compose strategy does for its own file. Written into the
     * generated compose as-is, Compose would interpolate them to ''.
     *
     * @param array<string, mixed> $decision
     * @return array<string, mixed>
     */
    public static function withPublicAddress(array $decision, ?string $publicUrl): array
    {
        if (!is_array($decision['env'] ?? null)) {
            return $decision;
        }
        foreach ($decision['env'] as $key => $value) {
            if (is_string($value)) {
                $decision['env'][$key] = ComposePlaceholders::withPublicAddress($value, $publicUrl);
            }
        }

        return $decision;
    }

    public static function isReserved(string $key): bool
    {
        return str_starts_with($key, self::RESERVED_PREFIX)
            || in_array($key, self::RESERVED, true);
    }

    /**
     * @param array<string, string> $env
     * @return array<string, string>
     */
    private static function overridable(array $env): array
    {
        return array_filter(
            $env,
            static fn (string $key): bool => !self::isReserved($key),
            ARRAY_FILTER_USE_KEY
        );
    }
}
