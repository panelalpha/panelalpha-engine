<?php

namespace Tests\Support;

use Dsc\Cron\Crontab;
use Dsc\Cron\FileHandler;

trait CrontabLibraryFixtures
{
    private function handlerPrinting(string $line): FileHandler
    {
        return new class ($line) extends FileHandler {
            public function __construct(private string $line)
            {
            }

            protected function crontabCommand(Crontab $crontab)
            {
                // parseExistingCrontab() appends ' -l'; `true` swallows it.
                return 'printf "%s\n" ' . escapeshellarg($this->line) . '; true';
            }
        };
    }
}
