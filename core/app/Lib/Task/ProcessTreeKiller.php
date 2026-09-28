<?php

namespace App\Lib\Task;

use App\System\ProcessRunner;

class ProcessTreeKiller
{
    public function __construct(private ProcessRunner $system)
    {
    }

    public function kill(int $pid): void
    {
        $pids = $this->collectDescendants($pid);
        $pids[] = $pid;

        $list = implode(' ', $pids);
        $this->system->runProcess("kill -TERM {$list} 2>/dev/null; true");

        $deadline = microtime(true) + 5;
        $alive = $pids;
        while (!empty($alive) && microtime(true) < $deadline) {
            $alive = [];
            foreach ($pids as $p) {
                $check = $this->system->runProcess("kill -0 {$p} 2>/dev/null");
                if ($check->getExitCode() === 0) {
                    $alive[] = $p;
                }
            }
            if (!empty($alive)) {
                usleep(200000);
            }
        }

        if (!empty($alive)) {
            $this->system->runProcess('kill -KILL ' . implode(' ', $alive) . ' 2>/dev/null; true');
        }
    }

    /**
     * @return array<int>
     */
    private function collectDescendants(int $pid): array
    {
        $result = [];
        $queue = [$pid];
        while (!empty($queue)) {
            $parent = array_shift($queue);
            $process = $this->system->runProcess(['ps', '-o', 'pid=', '--ppid', (string) $parent]);
            foreach (explode("\n", trim($process->getOutput())) as $line) {
                $child = (int) trim($line);
                if ($child > 0 && !in_array($child, $result, true)) {
                    $result[] = $child;
                    $queue[] = $child;
                }
            }
        }

        return $result;
    }
}
