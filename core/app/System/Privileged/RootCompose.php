<?php

namespace App\System\Privileged;

/** Compose commands as root. The broker allows them only on an account's own compose file and service. */
final class RootCompose
{
    private const AREA = 'compose';

    public function __construct(private readonly string $file) {}

    /** @return list<string> */
    public function up(bool $forceRecreate = false): array
    {
        return $this->compose(__FUNCTION__, [$forceRecreate ? 'force-recreate' : '']);
    }

    /** @return list<string> */
    public function down(): array
    {
        return $this->compose(__FUNCTION__, []);
    }

    /** @return list<string> */
    public function downWithVolumes(): array
    {
        return $this->compose(__FUNCTION__, []);
    }

    /** @return list<string> */
    public function runningServices(): array
    {
        return $this->compose(__FUNCTION__, []);
    }

    /** @return list<string> */
    public function build(): array
    {
        return $this->compose(__FUNCTION__, []);
    }

    /**
     * The broker takes `sites-http` in the engine's file, or the account's own service in its file.
     *
     * @return list<string>
     */
    public function restart(string $service): array
    {
        return $this->compose(__FUNCTION__, [Arguments::name($service)]);
    }

    /**
     * Stopped containers included, as JSON. The broker takes the same services as {@see restart()}.
     *
     * @return list<string>
     */
    public function serviceContainers(string $service): array
    {
        return $this->compose(__FUNCTION__, [Arguments::name($service)]);
    }

    /**
     * One `{{.CPUPerc}}` line.
     *
     * @return list<string>
     */
    public function cpuPercent(string $service): array
    {
        return $this->compose(__FUNCTION__, [Arguments::name($service)]);
    }

    /**
     * Without -T. The broker relays a pty of its own to the caller's terminal.
     *
     * @param  array<int, string>  $command
     * @param  array<string, string>  $environment
     * @return list<string>
     */
    public function execWithTty(
        string $service,
        array $command,
        array $environment = [],
        ?string $user = null,
        ?string $workDir = null
    ): array {
        $values = [
            ...$this->environmentValues($environment),
            $user === null ? '' : Arguments::owner($user),
            $workDir === null ? '' : Arguments::path($workDir),
            Arguments::name($service),
            ...array_values($command),
        ];

        return Root::action(self::AREA, __FUNCTION__, [Arguments::path($this->file), ...$values], terminal: true);
    }

    /**
     * @param  array<string, string>  $environment
     * @return list<string>
     */
    private function environmentValues(array $environment): array
    {
        foreach (array_keys($environment) as $name) {
            Arguments::environmentName((string) $name);
        }

        return ActionValues::pairs($environment);
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function compose(string $action, array $values): array
    {
        return Root::action(self::AREA, $action, [Arguments::path($this->file), ...$values]);
    }
}
