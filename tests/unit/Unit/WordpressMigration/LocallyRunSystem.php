<?php

namespace Tests\Unit\WordpressMigration;

use App\System;
use Symfony\Component\Process\Process;

/**
 * Runs every command for real as the test user, with `sudo` and the
 * account's `sudo setpriv ...` prefix dropped, and records who each one
 * would have run as in production: root, the account, or the engine.
 */
final class LocallyRunSystem extends System
{
    /** @var list<array{as: string, argv: list<string>}> */
    public array $calls = [];

    public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
    {
        $argv = array_values((array) $cmd);
        $this->calls[] = ['as' => $this->runsAs($argv), 'argv' => $argv];
        $process = new Process($this->withoutPrivilegeChange($argv));
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

    /** @param list<string> $argv */
    private function runsAs(array $argv): string
    {
        if (in_array('setpriv', $argv, true)) {
            return 'account';
        }

        return $argv[0] === 'sudo' ? 'root' : 'engine';
    }

    /**
     * @param  list<string>  $argv
     * @return list<string>
     */
    private function withoutPrivilegeChange(array $argv): array
    {
        $setpriv = array_search('setpriv', $argv, true);
        if ($setpriv !== false) {
            array_splice($argv, $setpriv - 1, 7);
        } elseif ($argv[0] === 'sudo') {
            array_shift($argv);
        }

        return array_values($argv);
    }
}
