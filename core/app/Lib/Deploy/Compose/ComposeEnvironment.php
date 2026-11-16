<?php

namespace App\Lib\Deploy\Compose;

use App\Lib\Project\ApiAccess;

/**
 * The environment a generated service runs with, in the order its layers outrank one another: a strategy's values are
 * defaults, the app config restates them and the account outranks both, except what {@see isReserved()}. No Laravel dependencies.
 */
final class ComposeEnvironment
{
    /** The generated entrypoint's own variables (`PA_DOCROOT`, `PA_DEPLOY_PHASE`): the engine reads them back. */
    public const RESERVED_PREFIX = 'PA_';

    /**
     * A stable secret per account, for an app that would derive one from its install path: every account's app is at `/app`,
     * so a salt or session name taken from `__DIR__` is the same on every tenant.
     */
    public const INSTANCE_SECRET = 'PA_INSTANCE_SECRET';

    /**
     * The server's mail relay, where a project sends its mail: the address exim listens on for accounts, and its port.
     */
    public const SMTP_HOST = 'PA_SMTP_HOST';

    public const SMTP_PORT = 'PA_SMTP_PORT';

    /**
     * Where the application is published and probed: an account that moved these would get a container nothing reaches.
     *
     * @var list<string>
     */
    public const RESERVED = ['HOST', 'HOSTNAME', 'PORT'];

    /**
     * Fold the app config and the account's values onto a decision's `env`, since `environment:` outranks `env_file:`.
     * An empty account value keeps what shipped; account values are literal, so their `$` is doubled for Compose.
     *
     * @param  array<string, mixed>  $decision
     * @param  array<string, string>  $appConfig  the project's `panelalpha.yaml`
     * @param  array<string, string>  $account  the account's own `env_vars`
     * @return array<string, mixed>
     */
    public static function layer(array $decision, array $appConfig, array $account): array
    {
        $overrides = array_merge(
            self::overridable(ComposeValues::stringMap($appConfig)),
            self::overridable(self::escapedForCompose(array_filter(
                ComposeValues::stringMap($account),
                static fn (string $value): bool => $value !== ''
            )))
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
     * @param  array<string, mixed>  $decision
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
     * The engine's own API address, token and CA ({@see ApiAccess}), reserved and written last so they reach the container.
     * A `$` is doubled, which is how Compose is told to keep it.
     *
     * @param  array<string, mixed>  $decision
     * @param  array<string, string>  $values
     * @return array<string, mixed>
     */
    public static function withApiAccess(array $decision, array $values): array
    {
        if ($values === []) {
            return $decision;
        }

        $decision['env'] = array_merge(
            ComposeValues::stringMap($decision['env'] ?? null),
            self::escapedForCompose($values)
        );

        return $decision;
    }

    /**
     * The server's mail relay added to a generated service's `environment:`, reserved: an account's own
     * value of the same name would send its mail somewhere else, or nowhere.
     *
     * @param  array<string, mixed>  $decision
     * @param  array<string, string>  $values  `PA_SMTP_HOST`, `PA_SMTP_PORT`
     * @return array<string, mixed>
     */
    public static function withMailRelay(array $decision, array $values): array
    {
        if ($values === []) {
            return $decision;
        }

        $decision['env'] = array_merge(
            ComposeValues::stringMap($decision['env'] ?? null),
            self::escapedForCompose($values)
        );

        return $decision;
    }

    /**
     * Values headed for a compose file's `environment:`, with `$` doubled, which
     * is how Compose is told to keep it instead of interpolating it.
     *
     * @param  array<string, string>  $values
     * @return array<string, string>
     */
    public static function escapedForCompose(array $values): array
    {
        return array_map(static fn (string $value): string => str_replace('$', '$$', $value), $values);
    }

    /**
     * `${PA_PUBLIC_URL}` / `${PA_PUBLIC_HOST}` in the decision's env resolved, as the compose strategy does for its own file;
     * written as-is, Compose would interpolate them to ''.
     *
     * @param  array<string, mixed>  $decision
     * @return array<string, mixed>
     */
    public static function withPublicAddress(array $decision, ?string $publicUrl): array
    {
        if (! is_array($decision['env'] ?? null)) {
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
            || in_array($key, self::RESERVED, true)
            || in_array($key, ApiAccess::ENV_KEYS, true);
    }

    /**
     * @param  array<string, string>  $env
     * @return array<string, string>
     */
    private static function overridable(array $env): array
    {
        return array_filter(
            $env,
            static fn (string $key): bool => ! self::isReserved($key),
            ARRAY_FILTER_USE_KEY
        );
    }
}
