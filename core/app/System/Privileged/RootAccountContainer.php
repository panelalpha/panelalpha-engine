<?php

namespace App\System\Privileged;

/**
 * A command in an account's own DinD container, through the host daemon's Engine API. The broker finds the container
 * by the account's name and checks it is that account's before anything runs.
 */
final class RootAccountContainer
{
    private const AREA = 'account-container';

    private const EXEC = self::AREA.'.exec';

    private const ENVIRONMENT_COUNT = 3;

    public function __construct(private readonly string $username) {}

    /**
     * $argv as $user in $workdir when given; the exit code is the command's.
     *
     * @param  list<string>  $argv
     * @param  array<string, string>  $environment
     * @return list<string>
     */
    public function exec(array $argv, string $user = '', string $workdir = '', array $environment = []): array
    {
        if ($argv === []) {
            throw InvalidArgument::of('command', '');
        }
        $pairs = [(string) count($environment)];
        foreach ($environment as $name => $value) {
            array_push($pairs, Arguments::environmentName((string) $name), self::withoutNul($value));
        }

        return Root::action(self::AREA, 'exec', [
            Arguments::username($this->username),
            $user === '' ? '' : Arguments::owner($user),
            $workdir === '' ? '' : Arguments::path($workdir),
            ...$pairs,
            ...array_map(self::withoutNul(...), $argv),
        ]);
    }

    /**
     * `1` when the container binds the daemon.json the engine renders, `0` when it carries its own.
     *
     * @return list<string>
     */
    public function daemonConfigMounted(): array
    {
        return Root::action(self::AREA, 'daemonConfigMounted', [Arguments::username($this->username)]);
    }

    /**
     * SIGHUP to the container's own dockerd, so it rereads daemon.json.
     *
     * @return list<string>
     */
    public function reloadDaemon(): array
    {
        return Root::action(self::AREA, 'reloadDaemon', [Arguments::username($this->username)]);
    }

    /** @param  array<int, string>  $argv */
    public function isExec(array $argv): bool
    {
        return Root::askedAction($argv) === self::EXEC && (Root::askedValues($argv)[0] ?? null) === $this->username;
    }

    /**
     * The same exec with $name=$value in its environment.
     *
     * @param  array<int, string>  $argv
     * @return list<string>
     */
    public function withEnvironment(array $argv, string $name, string $value): array
    {
        if (! $this->isExec($argv)) {
            throw InvalidArgument::of('account container exec', implode(' ', $argv));
        }

        return Root::reasked($argv, ActionValues::withPairInFront(Root::askedValues($argv), self::ENVIRONMENT_COUNT, [
            Arguments::environmentName($name), self::withoutNul($value),
        ]));
    }

    /**
     * The command an exec request runs, after its environment.
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
    public static function isExecRequest(array $argv): bool
    {
        return Root::askedAction($argv) === self::EXEC;
    }

    private static function withoutNul(string $value): string
    {
        if (str_contains($value, "\0")) {
            throw InvalidArgument::of('value', $value);
        }

        return $value;
    }
}
