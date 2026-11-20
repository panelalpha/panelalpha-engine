<?php

namespace Tests\Unit\System\Project;

use Dsc\Cron\Crontab;
use PHPUnit\Framework\TestCase;
use Tests\Support\CrontabLibraryFixtures;

class CrontabLibraryTest extends TestCase
{
    use CrontabLibraryFixtures;

    public function test_a_new_crontab_does_not_read_the_running_users_crontab(): void
    {
        // The handler would print one valid job if anything asked it to.
        $crontab = new Crontab();
        $crontab->setFileHandler($this->handlerPrinting('5 * * * * /usr/bin/leaked'));

        $this->assertSame([], $crontab->getJobs());
    }
}
