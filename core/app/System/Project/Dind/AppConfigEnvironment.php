<?php

namespace App\System\Project\Dind;

use App\Lib\Deploy\Compose\ComposeEnvironment;
use App\Lib\Deploy\Compose\ComposePlaceholders;
use App\System\Project\Dind as DindProject;

/**
 * The app config's `env:` for a project that runs its own compose file, which reaches it through `.env` the way the
 * account's env_vars do. A generated run file carries it in `environment:` ({@see DeployStrategy::composeDecision()}).
 */
final class AppConfigEnvironment
{
    private const PUBLIC_URL = '${'.ComposePlaceholders::PUBLIC_URL_VARIABLE;

    private const PUBLIC_HOST = '${'.ComposePlaceholders::PUBLIC_HOST_VARIABLE;

    public function __construct(private readonly DindProject $dind) {}

    /**
     * `${PA_PUBLIC_URL}` and `${PA_PUBLIC_HOST}` resolved, as the compose file's own are; the engine's reserved names left out.
     *
     * @return array<string, string>
     */
    public function values(): array
    {
        $env = $this->dind->appConfig($this->dind->userModel()->getGitRepo())?->env() ?? [];
        $values = array_filter(
            $env,
            static fn (string $key): bool => ! ComposeEnvironment::isReserved($key),
            ARRAY_FILTER_USE_KEY
        );

        return self::namesPublicAddress($values) ? $this->withPublicAddress($values) : $values;
    }

    /**
     * @param  array<string, string>  $values
     * @return array<string, string>
     */
    private function withPublicAddress(array $values): array
    {
        $url = $this->dind->publicAppUrl();

        return array_map(static fn (string $value): string => ComposePlaceholders::withPublicAddress($value, $url), $values);
    }

    /** @param array<string, string> $values */
    private static function namesPublicAddress(array $values): bool
    {
        foreach ($values as $value) {
            if (str_contains($value, self::PUBLIC_URL) || str_contains($value, self::PUBLIC_HOST)) {
                return true;
            }
        }

        return false;
    }
}
