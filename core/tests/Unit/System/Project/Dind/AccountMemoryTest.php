<?php

namespace Tests\Unit\System\Project\Dind;

use App\System\Project\Dind\AccountMemory;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/** A changed memory limit reaches the account's compose file, and nothing else in it moves. */
class AccountMemoryTest extends TestCase
{
    private const COMPOSE = <<<'YAML'
        services:
          dind:
            image: ghcr.io/panelalpha/engine-user-dind:v2.0.2
            restart: always
            container_name: shop
            tmpfs:
              - /run:mode=755,size=64m
              - /run/service:mode=755,size=4m,exec
            volumes:
              - /home/shop/:/home/shop/
            mem_limit: 2048M
            memswap_limit: 2048M
        networks:
          default:
            name: pash-default-network
            external: true
        YAML;

    public function test_it_changes_both_memory_keys_and_nothing_else(): void
    {
        [$yaml, $container] = AccountMemory::withLimit(self::COMPOSE, 512);
        $before = Yaml::parse(self::COMPOSE);
        $after = Yaml::parse($yaml);

        $this->assertSame('shop', $container);
        $this->assertSame('512M', $after['services']['dind']['mem_limit']);
        $this->assertSame('512M', $after['services']['dind']['memswap_limit']);
        unset($before['services']['dind']['mem_limit'], $before['services']['dind']['memswap_limit']);
        unset($after['services']['dind']['mem_limit'], $after['services']['dind']['memswap_limit']);
        $this->assertSame($before, $after);
    }

    /** An account created before every project had a limit has no memory keys at all. */
    public function test_an_account_without_a_limit_gets_one(): void
    {
        $unlimited = str_replace(["    mem_limit: 2048M\n", "    memswap_limit: 2048M\n"], '', self::COMPOSE);

        [$yaml] = AccountMemory::withLimit($unlimited, 1024);

        $this->assertSame('1024M', Yaml::parse($yaml)['services']['dind']['mem_limit']);
    }

    public function test_a_file_without_the_account_service_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        AccountMemory::withLimit("services:\n  web:\n    image: nginx\n", 512);
    }
}
