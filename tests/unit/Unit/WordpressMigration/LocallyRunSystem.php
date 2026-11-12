<?php

namespace Tests\Unit\WordpressMigration;

use App\System;
use Symfony\Component\Process\Process;
use Tests\Support\LocalRoot;

/**
 * Runs every command for real as the test user, broker requests through pa-root-local, and
 * records who each one would have run as in production: root, the account, or the engine.
 */
final class LocallyRunSystem extends System
{
    /** @var list<array{as: string, argv: list<string>}> */
    public array $calls = [];

    public function __construct(private string $homes) {}

    public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
    {
        $argv = array_values((array) $cmd);
        $this->calls[] = ['as' => $this->runsAs($argv), 'argv' => $argv];
        $process = new Process($this->locally($argv), null, ['PA_ROOT_LOCAL_ROOTS' => json_encode(['homes' => $this->homes])]);
        $process->run();

        return $process;
    }

    /** @return list<list<string>> */
    public function callsAs(string $who): array
    {
        return array_values(array_map(
            static fn (array $call): array => $call['argv'],
            array_filter($this->calls, static fn (array $call): bool => $call['as'] === $who),
        ));
    }

    /**
     * The broker request in $argv, direct or fed on stdin.
     *
     * @param  list<string>  $argv
     * @return ?list<string>
     */
    public static function request(array $argv): ?array
    {
        $at = array_search(LocalRoot::CLIENT, $argv, true);

        return $at === false ? null : array_slice($argv, $at);
    }

    /** @param list<string> $argv */
    private function runsAs(array $argv): string
    {
        $request = self::request($argv);
        if ($request === null) {
            return 'engine';
        }

        return str_starts_with((string) LocalRoot::action($request), 'account.') ? 'account' : 'root';
    }

    /**
     * @param  list<string>  $argv
     * @return list<string>
     */
    private function locally(array $argv): array
    {
        $at = array_search(LocalRoot::CLIENT, $argv, true);

        return $at === false ? $argv : [...array_slice($argv, 0, $at), ...LocalRoot::argv(array_slice($argv, $at))];
    }
}
