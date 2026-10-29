<?php

namespace Tests\Support;

use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\System\ProcessRunner;
use Symfony\Component\Process\Process;

/** A host as UfwFirewall's fail2ban lists see it: files kept in memory, reloads counted. */
final class Fail2banHost implements ProcessRunner
{
    /** @var array<string, string> path => content */
    public array $files = [];

    /** How often `firewall.sh --fail2ban` applied the lists. */
    public int $reloads = 0;

    public bool $reloadFails = false;

    public function runProcessOnHost(string|array $cmd, array $env = [], int $timeout = 600): Process
    {
        $cmd = (array) $cmd;

        return match (true) {
            $cmd[0] === 'cat' => isset($this->files[$cmd[1]]) ? FakeProcess::ok($this->files[$cmd[1]]) : FakeProcess::failed('No such file'),
            $cmd[0] === 'sh' && str_contains($cmd[2] ?? '', 'printf') => $this->write($cmd[3], $cmd[4]),
            $cmd[0] === 'bash' && end($cmd) === '--fail2ban' => $this->reload(),
            default => FakeProcess::ok(),
        };
    }

    public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
    {
        return '';
    }

    public function execOnHost(string|array $cmd, array $env = [], int $timeout = 600): string
    {
        return '';
    }

    public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
    {
        return FakeProcess::ok();
    }

    public function runProcessWithCallbacks(string|array $cmd, array $env = [], int $timeout = 600, ?callable $onStart = null, ?callable $onOutput = null, ?StepWatchdog $watchdog = null): Process
    {
        return FakeProcess::ok();
    }

    private function write(string $path, string $content): Process
    {
        $this->files[$path] = $content;

        return FakeProcess::ok();
    }

    private function reload(): Process
    {
        $this->reloads++;

        return $this->reloadFails ? FakeProcess::failed('fail2ban did not start') : FakeProcess::ok();
    }
}
