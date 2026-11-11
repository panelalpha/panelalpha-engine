<?php

namespace App\System\Project\Dind;

use App\Exceptions\DockerErrorException;
use App\Lib\Limits\ResourceLimit;
use App\System\Privileged;
use App\System\Project\Dind;
use RuntimeException;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * Puts a project's CPU limit in its account's compose file and on the running container, nothing else:
 * re-rendering the template would move an older account onto today's image and mounts.
 */
final class AccountCpu
{
    private const SERVICE = 'dind';

    public function __construct(private readonly Dind $project) {}

    /**
     * No limit leaves `cpus` out of the compose file. Docker cannot take a running container's cap off, so a capped
     * account is given every CPU of the host instead, until it is recreated without one.
     *
     * @throws CpuLimitDeferred when the running account cannot take it; the compose file carries it all the same
     */
    public function apply(): void
    {
        $cpus = self::limitOf($this->project->userModel()->getCpuLimit());
        $container = $this->writeCompose($cpus);

        if (! $this->project->isRunning()) {
            return;
        }
        $live = $cpus ?? $this->everyHostCpuIfCapped($container);
        if ($live !== null) {
            $this->limitRunning($container, $live);
        }
    }

    /** What the account is capped at: null for an unset, unlimited or zero limit, which the template leaves out. */
    public static function limitOf(int|float|null $stored): ?float
    {
        $cpus = ResourceLimit::enforced($stored);

        return $cpus !== null && $cpus > 0 ? (float) $cpus : null;
    }

    private function writeCompose(?float $cpus): string
    {
        $filesystem = $this->project->system()->filesystem();
        $path = $this->project->composeFilePath();

        [$compose, $container] = self::withLimit($filesystem->fileGetContents($path), $cpus);
        if ($compose !== null) {
            $filesystem->writeFileReplacingPath($path, $compose);
        }

        return $container ?? $this->project->userModel()->username;
    }

    /**
     * The compose file with the account capped at $cpus, null when it already is; and its container name.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function withLimit(string $composeYaml, ?float $cpus): array
    {
        $compose = Yaml::parse($composeYaml);
        if (! is_array($compose) || ! is_array($compose['services'][self::SERVICE] ?? null)) {
            throw new RuntimeException("No '".self::SERVICE."' service in the account's compose file.");
        }
        $name = $compose['services'][self::SERVICE]['container_name'] ?? null;
        $container = is_string($name) && $name !== '' ? $name : null;

        $current = $compose['services'][self::SERVICE]['cpus'] ?? null;
        if ((is_numeric($current) ? (float) $current : null) === $cpus) {
            return [null, $container];
        }

        if ($cpus === null) {
            unset($compose['services'][self::SERVICE]['cpus']);
        } else {
            $compose['services'][self::SERVICE]['cpus'] = $cpus;
        }

        return [Yaml::dump($compose, 8, 2), $container];
    }

    private function everyHostCpuIfCapped(string $container): ?float
    {
        $read = $this->project->system()->runProcess(Privileged::docker()->nanoCpus($container), [], 30);
        $nanoCpus = trim(self::answered($read)->getOutput());
        if ($nanoCpus === '' || $nanoCpus === '0') {
            return null;
        }

        $info = json_decode(trim($this->project->system()->exec(Privileged::docker()->info(), [], 30)), true);
        $hostCpus = is_array($info) ? ($info['NCPU'] ?? null) : null;
        if (! is_int($hostCpus) || $hostCpus < 1) {
            throw CpuLimitDeferred::because('Docker did not say how many CPUs the host has');
        }

        return (float) $hostCpus;
    }

    private function limitRunning(string $container, float $cpus): void
    {
        self::answered($this->project->system()->runProcess(Privileged::docker()->limitCpu($container, $cpus)));
    }

    /** The broker's answer; a broker that refuses the action, as one from before it does, leaves the limit to the next recreate. */
    private static function answered(Process $process): Process
    {
        if ($process->getExitCode() === Privileged::REFUSED) {
            throw CpuLimitDeferred::refusedByTheRootBroker($process->getErrorOutput());
        }
        if (! $process->isSuccessful()) {
            throw DockerErrorException::orPlainFailure($process->getErrorOutput() ?: $process->getOutput());
        }

        return $process;
    }
}
