<?php

namespace App\Console\Commands\Concerns;

use App\Lib\Deploy\DeployLog\DeployLineFormat;
use App\Lib\Deploy\DeployLog\DeployLogger;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A command that runs a deploy in its own process prints the deploy log as the
 * pipeline writes it: DeployLogStream is process-local, so the command
 * subscribes to the same lines the NDJSON API stream carries -- no polling,
 * no second process, and nothing to miss between reads.
 *
 * `--quiet-deploy` turns it off; `--verbose` adds the dim subprocess output,
 * which is the build's own stdout and runs to thousands of lines. Under
 * `--json` the log goes to stderr, so a caller that captures stdout still gets
 * nothing but the JSON.
 */
trait FollowsDeployLog
{
    protected function followDeployLog(): void
    {
        if ($this->option('quiet-deploy')) {
            return;
        }

        $out = $this->deployOutput();
        $verbose = $this->output->isVerbose();
        $startedAt = 0;

        DeployLogger::streamTo(function (array $frame) use ($out, &$startedAt, $verbose): void {
            if (($frame['type'] ?? null) !== 'line') {
                return;
            }
            if ($startedAt === 0) {
                $startedAt = (int) ($frame['ts'] ?? time());
            }

            $line = DeployLineFormat::line($frame, $startedAt, $verbose);
            if ($line !== null) {
                $out->writeln($line);
            }
        });
    }

    protected function stopFollowingDeployLog(): void
    {
        DeployLogger::stopStreaming();
    }

    /** stderr under --json, so stdout carries the JSON and nothing else. */
    protected function deployOutput(): OutputInterface
    {
        $out = $this->output->getOutput();
        $json = $this->hasOption('json') && $this->option('json');

        return $json && $out instanceof ConsoleOutputInterface ? $out->getErrorOutput() : $out;
    }
}
