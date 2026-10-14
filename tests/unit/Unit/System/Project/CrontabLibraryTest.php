<?php

namespace Tests\Unit\System\Project;

use Dsc\Cron\Crontab;
use Dsc\Cron\FileHandler;
use PHPUnit\Framework\TestCase;

class CrontabLibraryTest extends TestCase
{
    public function test_a_new_crontab_does_not_read_the_running_users_crontab(): void
    {
        // The handler would print one valid job if anything asked it to.
        $crontab = new Crontab();
        $crontab->setFileHandler($this->handlerPrinting('5 * * * * /usr/bin/leaked'));

        $this->assertSame([], $crontab->getJobs());
    }

    public function test_an_unparsable_line_is_a_readable_error(): void
    {
        $crontab = new Crontab();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('when parsing cron job: not-a-cron-line');

        $this->handlerPrinting('not-a-cron-line')->parseExistingCrontab($crontab);
    }

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
