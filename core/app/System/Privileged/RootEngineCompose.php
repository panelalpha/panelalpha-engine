<?php

namespace App\System\Privileged;

/** Compose commands as root on the engine's own project. */
final class RootEngineCompose
{
    private const AREA = 'compose';

    public function __construct(private readonly string $projectDirectory) {}

    /** @return list<string> */
    public function upWithoutDependencies(string $service): array
    {
        return $this->compose('engineUpWithoutDependencies', [Arguments::name($service)]);
    }

    /**
     * The running mariadbd's three cache sizes in bytes, on one line.
     *
     * @return list<string>
     */
    public function sitesDbCacheSizes(): array
    {
        return $this->compose(__FUNCTION__, []);
    }

    /**
     * Sets the site database's cache sizes in the engine's compose `.env` in place. The broker takes only the three
     * SITES_DB_* sizes, each value one word `.env` keeps unquoted, and never writes `.env-core`.
     *
     * @param  array<string, string>  $settings
     * @return list<string>
     */
    public function setEnv(array $settings): array
    {
        $pairs = [];
        foreach ($settings as $key => $value) {
            array_push($pairs, Arguments::environmentName($key), Arguments::settingValue($value));
        }

        return $this->compose('engineSetEnv', $pairs);
    }

    /**
     * `KEY=value` for each of $keys, all site database cache sizes, a live line of the engine's compose `.env` sets.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    public function envValues(array $keys): array
    {
        return $this->compose('engineEnvValues', array_map(Arguments::environmentName(...), $keys));
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function compose(string $action, array $values): array
    {
        return Root::action(self::AREA, $action, [Arguments::path($this->projectDirectory), ...$values]);
    }
}
