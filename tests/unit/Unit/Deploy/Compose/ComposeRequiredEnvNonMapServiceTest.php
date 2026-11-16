<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\ComposeRequiredEnv;
use PHPUnit\Framework\TestCase;

class ComposeRequiredEnvNonMapServiceTest extends TestCase
{
    public function test_a_service_that_is_not_a_map_is_skipped(): void
    {
        $yaml = "services:\n  junk: ~\n  db:\n    image: postgres:16\n    environment:\n"
            ."      POSTGRES_PASSWORD: \${DB_PASS}\n      POSTGRES_USER: \${DB_USER}\n";

        $this->assertSame(
            ['DB_PASS' => 'd55fab49cebac2673a69093bacf8d79ad65e67e14a31c0a0', 'DB_USER' => 'app'],
            ComposeRequiredEnv::missing([['dir' => '', 'yaml' => $yaml]], [], 'seed')
        );
    }
}
