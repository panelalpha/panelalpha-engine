<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\ComposeOverride;
use PHPUnit\Framework\TestCase;

/** An override that is no document, or whose services are no map, is not rewritten. */
class ComposeOverrideUnreadableTest extends TestCase
{
    public function test_a_file_that_is_no_map_cannot_be_read(): void
    {
        $this->assertSame(['yaml' => null, 'dropped' => []], ComposeOverride::withoutUndefinedServices("just a sentence\n", ['app']));
    }

    public function test_services_that_are_no_map_are_left_as_written(): void
    {
        $raw = "services: nothing\n";

        $this->assertSame(['yaml' => $raw, 'dropped' => []], ComposeOverride::withoutUndefinedServices($raw, ['app']));
    }
}
