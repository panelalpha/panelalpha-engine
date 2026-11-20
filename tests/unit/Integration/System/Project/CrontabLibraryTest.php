<?php

namespace Tests\Integration\System\Project;

use Dsc\Cron\Crontab;
use PHPUnit\Framework\TestCase;
use Tests\Support\CrontabLibraryFixtures;

class CrontabLibraryTest extends TestCase
{
    use CrontabLibraryFixtures;

    public function test_an_unparsable_line_is_a_readable_error(): void
    {
        $crontab = new Crontab();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('when parsing cron job: not-a-cron-line');

        $this->handlerPrinting('not-a-cron-line')->parseExistingCrontab($crontab);
    }
}
