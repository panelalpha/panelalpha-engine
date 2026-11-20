<?php

namespace Tests\Unit\WordpressMigration;

use App\Models\WordpressMigration;
use App\Support\JobBudget;
use App\System\Project\PhpHosting\WordPress\Migration\CurlSourceHttpClient;
use PHPUnit\Framework\TestCase;

class WordpressMigrationSourceWaitTest extends TestCase
{
    public function test_a_source_call_may_outlast_the_stale_window_but_not_the_job(): void
    {
        $this->assertSame(15, WordpressMigration::STALE_MINUTES);
        $this->assertGreaterThan(WordpressMigration::STALE_MINUTES * 60, CurlSourceHttpClient::REQUEST_TIMEOUT_SECONDS);
        $this->assertLessThan(JobBudget::SECONDS, CurlSourceHttpClient::REQUEST_TIMEOUT_SECONDS);
    }
}
