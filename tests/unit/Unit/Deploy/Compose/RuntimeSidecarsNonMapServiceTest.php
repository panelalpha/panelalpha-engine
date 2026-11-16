<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\RuntimeSidecars;
use PHPUnit\Framework\TestCase;

class RuntimeSidecarsNonMapServiceTest extends TestCase
{
    public function test_a_service_that_is_not_a_map_is_dropped(): void
    {
        $result = RuntimeSidecars::fromYaml("services:\n  junk: ~\n  db:\n    image: postgres:16\n");

        $this->assertSame(['db'], array_keys($result['services']));
        $this->assertSame(['POSTGRES_DB' => 'app', 'POSTGRES_USER' => 'app', 'POSTGRES_PASSWORD' => 'app'], $result['services']['db']['environment']);
        $this->assertSame('postgres://app:app@db:5432/app', $result['env']['DATABASE_URL']);
        $this->assertNull($result['build_image']);
        $this->assertSame([], $result['dropped_test_services']);
    }
}
