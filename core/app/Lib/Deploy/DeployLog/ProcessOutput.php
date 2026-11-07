<?php

namespace App\Lib\Deploy\DeployLog;

/**
 * Turns subprocess output, which arrives in chunks that routinely end mid-line, into whole log lines,
 * dropping noise (progress rewrites, repeats of the previous line) on the way.
 */
final class ProcessOutput
{
    /** @var array<string, string> carry buffer per stream type */
    private array $partial = [];

    private ?string $lastLine = null;

    /**
     * @return list<string> complete lines worth logging
     */
    public function consume(string $type, string $data): array
    {
        $lines = explode("\n", ($this->partial[$type] ?? '').$data);
        $this->partial[$type] = array_pop($lines);

        return $this->keepInteresting($lines);
    }

    /**
     * @return list<string> whatever was left mid-line
     */
    public function flush(): array
    {
        $remainders = array_values($this->partial);
        $this->partial = [];

        return array_values(array_filter(array_map(LogLine::sanitize(...), $remainders)));
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function keepInteresting(array $lines): array
    {
        $kept = [];
        foreach ($lines as $line) {
            $line = LogLine::sanitize(LogLine::lastOverwrite($line));
            if ($this->isWorthLogging($line)) {
                $this->lastLine = $line;
                $kept[] = $line;
            }
        }

        return $kept;
    }

    private function isWorthLogging(string $line): bool
    {
        return $line !== ''
            && $line !== $this->lastLine
            && ! DeployLogFilter::shouldSkip($line);
    }
}
