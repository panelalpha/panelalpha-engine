<?php

namespace App\Console\Commands\Concerns;

use App\Lib\Findings\Hints\FindingPresenter;

/** Findings on a terminal: each sentence, then the commands that act on it. Other surfaces' hints are not shown. */
trait PrintsFindings
{
    private function printFindings(mixed $findings, ?string $username): void
    {
        foreach (FindingPresenter::present($findings, $username) as $finding) {
            $this->warn((string) $finding['message']);
            $this->printCliHints($finding['hints']);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $hints
     */
    private function printCliHints(array $hints): void
    {
        foreach ($hints as $hint) {
            if ($hint['type'] === 'cli') {
                $this->line('  '.$hint['command']);
            }
        }
    }
}
