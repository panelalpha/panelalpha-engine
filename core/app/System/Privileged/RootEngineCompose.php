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
     * @param  list<string>  $values
     * @return list<string>
     */
    private function compose(string $action, array $values): array
    {
        return Root::action(self::AREA, $action, [Arguments::path($this->projectDirectory), ...$values]);
    }
}
