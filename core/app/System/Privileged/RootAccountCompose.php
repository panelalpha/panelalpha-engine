<?php

namespace App\System\Privileged;

/**
 * `docker compose` and `docker buildx build` for an account's stack, run by the broker as the account against its own
 * daemon, outside the account's container.
 */
final class RootAccountCompose
{
    private const AREA = 'account-compose';

    private const RUN = self::AREA.'.run';

    private const CLIENTS = ['compose', 'buildx'];

    private const ROOT = 'root';

    private const ENVIRONMENT_COUNT = 3;

    /** What the broker's sandbox starts the client with besides the request's environment. */
    private const CLIENT_PATH = '/usr/bin';

    private const CLIENT_DOCKER_HOST = 'unix:///run/docker.sock';

    public function __construct(private readonly string $username) {}

    /**
     * The process environment the client of a {@see run()} with $environment gets, in the account whose home is $home.
     *
     * @param  array<string, string>  $environment
     * @return array<string, string>
     */
    public function clientEnvironment(string $home, array $environment): array
    {
        return [
            ...$environment,
            'PATH' => self::CLIENT_PATH,
            'HOME' => rtrim($home, '/'),
            'USER' => $this->username,
            'LOGNAME' => $this->username,
            'DOCKER_HOST' => self::CLIENT_DOCKER_HOST,
        ];
    }

    /**
     * `docker compose ...` or `docker buildx build ...` in $workdir, else the home; the exit code is the client's.
     * With $asRoot the client stands in for root in the account and keeps root's reach over the home's files.
     *
     * @param  list<string>  $argv
     * @param  array<string, string>  $environment
     * @return list<string>
     */
    public function run(array $argv, string $workdir = '', array $environment = [], bool $asRoot = false): array
    {
        if (! self::isClient($argv)) {
            throw InvalidArgument::of('docker client', implode(' ', $argv));
        }
        $pairs = [(string) count($environment)];
        foreach ($environment as $name => $value) {
            array_push($pairs, Arguments::environmentName((string) $name), self::withoutNul($value));
        }

        return Root::action(self::AREA, 'run', [
            Arguments::username($this->username),
            $asRoot ? self::ROOT : '',
            $workdir === '' ? '' : Arguments::path($workdir),
            ...$pairs,
            ...array_map(self::withoutNul(...), $argv),
        ]);
    }

    /**
     * The request for a `[env NAME=value …] docker compose …` or `docker buildx build …` command line: what `env` sets
     * goes over $environment, and its PWD is the workdir. Null for any other command.
     *
     * @param  array<int, string>  $cmd
     * @param  array<string, string>  $environment
     * @return ?list<string>
     */
    public function forCommand(array $cmd, array $environment = [], bool $asRoot = false): ?array
    {
        $cmd = array_values($cmd);
        $at = 0;
        if (($cmd[0] ?? null) === 'env') {
            for ($at = 1; preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/s', (string) ($cmd[$at] ?? ''), $m) === 1; $at++) {
                $environment[$m[1]] = $m[2];
            }
        }
        $argv = array_slice($cmd, $at + 1);
        if (($cmd[$at] ?? null) !== 'docker' || ! self::isClient($argv)) {
            return null;
        }

        return $this->run($argv, $environment['PWD'] ?? '', $environment, $asRoot);
    }

    /** @param  array<int, string>  $argv */
    public static function isRunRequest(array $argv): bool
    {
        return Root::askedAction($argv) === self::RUN;
    }

    /**
     * The docker client argv a run request runs, after its environment.
     *
     * @param  array<int, string>  $argv
     * @return list<string>
     */
    public static function commandOf(array $argv): array
    {
        $values = Root::askedValues($argv);

        return array_slice($values, self::ENVIRONMENT_COUNT + 1 + 2 * (int) ($values[self::ENVIRONMENT_COUNT] ?? 0));
    }

    /** @param  array<int, string>  $argv */
    private static function isClient(array $argv): bool
    {
        return in_array($argv[0] ?? null, self::CLIENTS, true) && ($argv[0] !== 'buildx' || ($argv[1] ?? null) === 'build');
    }

    private static function withoutNul(string $value): string
    {
        if (str_contains($value, "\0")) {
            throw InvalidArgument::of('value', $value);
        }

        return $value;
    }
}
