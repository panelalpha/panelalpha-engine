<?php

namespace App\Console\Commands\Deploy;

use App\Lib\Deploy\DeployLog\DeployLineFormat;
use App\Lib\Deploy\DeployLog\DeployLogger;
use Illuminate\Console\Command;

class DeployLogsShowCommand extends Command
{
    /**
     * How long --follow waits for a deploy log to appear at all. Its own short
     * grace period, not --timeout: that one is for a deploy that is running,
     * and a mistyped project name should not hang for half an hour.
     */
    private const APPEAR_GRACE = 60;

    /** Older spellings still answer, so nothing scripted against them breaks. */
    protected $aliases = ['deploy:log:show', 'deploy-logs:show'];

    protected $signature = 'project:deploy:log
                            {project : Project username}
                            {--id= : Deploy id (defaults to latest.json)}
                            {--tail=80 : Number of lines from the end}
                            {--follow : Wait for a running deploy: print lines as they arrive, exit when it finishes}
                            {--timeout=1800 : With --follow, seconds to wait before giving up}
                            {--raw : Print raw JSONL instead of formatted lines}';

    protected $description = 'Show deploy log lines for a project (latest deploy, or --id); --follow waits for a running one';

    public function handle(): int
    {
        $username = (string)$this->argument('project');
        $deployId = $this->option('id');
        $tail = max(1, (int)$this->option('tail'));
        $raw = (bool)$this->option('raw');
        $follow = (bool)$this->option('follow');
        $timeout = max(1, (int)$this->option('timeout'));

        try {
            if ($deployId !== null && $deployId !== '') {
                $logger = DeployLogger::forDeploy($username, (string)$deployId);
            } else {
                $logger = $follow
                    ? $this->awaitCurrent($username, $timeout)
                    : DeployLogger::current($username);
                if ($logger === null) {
                    $this->error("No deploy log found for '{$username}' (missing latest.json)");
                    return 1;
                }
            }
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
            return 1;
        }

        $path = $logger->getLogPath();
        // Following a deploy that has only just been queued: the status file
        // names it before the first line is written.
        if (!is_file($path) && $follow) {
            $this->awaitFile($path, $timeout);
        }
        if (!is_file($path)) {
            $this->error("Log file not found: {$path}");
            return 1;
        }

        $latestMeta = DeployLogger::readLatestFor($username);

        $this->line("User:    {$username}");
        $this->line('Deploy:  ' . $logger->getDeployId());
        $this->line('Path:    ' . $path);
        if ($latestMeta !== null && ($latestMeta['id'] ?? null) === $logger->getDeployId()) {
            $this->line('Status:  ' . ($latestMeta['status'] ?? '-'));
            $this->line('Stage:   ' . ($latestMeta['stage'] ?? '-'));
            $this->line('PID:     ' . (($latestMeta['pid'] ?? null) !== null ? (string)$latestMeta['pid'] : '-'));
            if (!empty($latestMeta['error'])) {
                $this->line('Error:   ' . $latestMeta['error']);
            }
        } elseif ($latestMeta !== null) {
            $this->line('Note:    latest.json points to ' . ($latestMeta['id'] ?? '?'));
        }
        $this->line('');

        if ($follow) {
            return $this->follow($logger, $username, $tail, $timeout, $raw);
        }

        $all = file($path, FILE_IGNORE_NEW_LINES);
        if ($all === false) {
            $this->error("Could not read {$path}");
            return 1;
        }
        $slice = array_slice($all, -$tail);

        if ($raw) {
            foreach ($slice as $line) {
                $this->line($line);
            }
            return 0;
        }

        foreach ($slice as $line) {
            /** @var mixed $decoded */
            $decoded = json_decode($line, true);
            if (!is_array($decoded)) {
                $this->line($line);
                continue;
            }
            $ts = isset($decoded['ts']) ? date('Y-m-d H:i:s', (int)$decoded['ts']) : '-';
            $level = (string)($decoded['level'] ?? 'dim');
            $msg = (string)($decoded['msg'] ?? '');
            $this->line(sprintf('[%s] %-5s %s', $ts, $level, $msg));
        }

        return 0;
    }

    /**
     * Poll the log until the deploy reaches a terminal status, printing what
     * arrives. The status file is what says it is over -- not the absence of
     * new lines, which is what a slow `npm install` looks like too.
     *
     * Exit code is the deploy's own verdict, so this is also how a script
     * waits for a deploy somebody else started.
     */
    private function follow(DeployLogger $logger, string $username, int $tail, int $timeout, bool $raw): int
    {
        $verbose = $this->output->isVerbose();
        $deadline = time() + $timeout;

        $page = $logger->read(0);
        $lines = $page['lines'];
        $startedAt = $lines === [] ? time() : (int)($lines[0]['ts'] ?? time());
        // The backlog, so a deploy already halfway through does not start
        // mid-sentence, then everything new from where that ended.
        foreach (array_slice($lines, -$tail) as $line) {
            $this->write($line, $startedAt, $verbose, $raw);
        }
        $offset = $page['next_offset'];

        while (true) {
            $status = $this->status($username, $logger->getDeployId());

            $page = $logger->read($offset);
            foreach ($page['lines'] as $line) {
                $this->write($line, $startedAt, $verbose, $raw);
            }
            $offset = $page['next_offset'];

            // Status read before the lines, so a deploy that finished between
            // the two is still drained above rather than cut off mid-log.
            if ($status === null) {
                // latest.json names a different deploy: this one is over, and
                // waiting for a status it will never get again is a 30-minute
                // hang on `--id` for an older deploy.
                $this->line('');
                $this->warn('This is not the current deploy for the project; nothing more will arrive.');

                return 0;
            }

            if (DeployLineFormat::isTerminal($status)) {
                $this->line('');
                $failed = in_array(
                    $status,
                    [DeployLogger::STATUS_FAILED, DeployLogger::STATUS_CANCELLED],
                    true
                );
                $failed
                    ? $this->error("Deploy {$status}.")
                    : $this->info("Deploy {$status}.");

                return $failed ? 1 : 0;
            }

            if (time() >= $deadline) {
                $this->line('');
                $this->error("Still running after {$timeout}s; giving up on watching it.");
                return 1;
            }

            usleep(500_000);
        }
    }

    /** @param array{ts?: int, stage?: ?string, level?: string, msg?: string} $line */
    private function write(array $line, int $startedAt, bool $verbose, bool $raw): void
    {
        if ($raw) {
            $this->line((string) json_encode($line));

            return;
        }

        $rendered = DeployLineFormat::line($line, $startedAt, $verbose);
        if ($rendered !== null) {
            $this->output->writeln($rendered);
        }
    }

    /** The status of the deploy being followed, or null for somebody else's. */
    private function status(string $username, string $deployId): ?string
    {
        $latest = DeployLogger::readLatestFor($username);
        if ($latest === null || ($latest['id'] ?? null) !== $deployId) {
            return null;
        }

        $status = $latest['status'] ?? null;

        return is_string($status) ? $status : null;
    }

    /**
     * A create that is still provisioning has no deploy log yet, so --follow
     * waits for one rather than reporting there is none.
     */
    private function awaitCurrent(string $username, int $timeout): ?DeployLogger
    {
        $deadline = time() + min(self::APPEAR_GRACE, $timeout);
        do {
            $logger = DeployLogger::current($username);
            if ($logger !== null) {
                return $logger;
            }
            usleep(500_000);
        } while (time() < $deadline);

        return null;
    }

    private function awaitFile(string $path, int $timeout): void
    {
        $deadline = time() + min(self::APPEAR_GRACE, $timeout);
        while (!is_file($path) && time() < $deadline) {
            usleep(500_000);
        }
    }
}
