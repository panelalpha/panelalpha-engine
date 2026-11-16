<?php

namespace Tests\Integration\Support;

use App\Support\EngineEnvFile;
use App\Support\EnvFile;
use App\System;
use App\System\Privileged;
use App\System\Privileged\InvalidArgument;
use RuntimeException;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * php-fpm runs as www-data and may not write compose's `.env`, so a sites-db size from the API goes to the root broker
 * as compose.engineSetEnv, and is read back with compose.engineEnvValues. `.env-core` never goes to the broker.
 */
class EngineEnvFileBrokerTest extends TestCase
{
    private const ENGINE = '/opt/panelalpha/shared-hosting';

    public function test_a_change_goes_to_the_broker_with_only_the_keys_that_change(): void
    {
        $system = $this->system("SITES_DB_KEY_BUFFER_SIZE=8M\n");
        $env = new EngineEnvFile(new EnvFile('/nonexistent/.env'), EngineEnvFile::COMPOSE, $system, direct: false);

        $changed = $env->set(['SITES_DB_KEY_BUFFER_SIZE' => '8M', 'SITES_DB_ARIA_PAGECACHE_BUFFER_SIZE' => '16M']);

        $this->assertSame(['SITES_DB_ARIA_PAGECACHE_BUFFER_SIZE'], $changed);
        $this->assertSame(['compose.engineEnvValues', 'compose.engineSetEnv'], array_map(LocalRoot::action(...), $system->ran));
        $this->assertSame([self::ENGINE, 'SITES_DB_ARIA_PAGECACHE_BUFFER_SIZE', '16M'], LocalRoot::values($system->ran[1]));
    }

    public function test_nothing_is_asked_to_change_when_nothing_differs(): void
    {
        $system = $this->system("SITES_DB_KEY_BUFFER_SIZE=16M\n");
        $env = new EngineEnvFile(new EnvFile('/nonexistent/.env'), EngineEnvFile::COMPOSE, $system, direct: false);

        $this->assertSame([], $env->set(['SITES_DB_KEY_BUFFER_SIZE' => '16M']));
        $this->assertSame(['compose.engineEnvValues'], array_map(LocalRoot::action(...), $system->ran));
    }

    public function test_env_core_is_never_asked_of_the_broker(): void
    {
        $system = $this->system("APP_URL=https://evil.example\n");
        $env = new EngineEnvFile(new EnvFile('/nonexistent/.env-core'), EngineEnvFile::CORE, $system, direct: false);

        $this->assertSame(['APP_URL' => null], $env->values(['APP_URL']));
        try {
            $env->set(['APP_URL' => 'https://evil.example']);
            $this->fail('.env-core was written');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame([], $system->ran);
    }

    public function test_an_unreadable_file_is_read_through_the_broker(): void
    {
        $system = $this->system("SITES_DB_KEY_BUFFER_SIZE=16M\n");
        $env = new EngineEnvFile(new EnvFile('/nonexistent/.env'), EngineEnvFile::COMPOSE, $system, direct: false);

        $this->assertSame(['SITES_DB_KEY_BUFFER_SIZE' => '16M', 'SITES_DB_ARIA_PAGECACHE_BUFFER_SIZE' => null], $env->values(['SITES_DB_KEY_BUFFER_SIZE', 'SITES_DB_ARIA_PAGECACHE_BUFFER_SIZE']));
        $this->assertSame([self::ENGINE, 'SITES_DB_KEY_BUFFER_SIZE', 'SITES_DB_ARIA_PAGECACHE_BUFFER_SIZE'], LocalRoot::values($system->ran[0]));
    }

    public function test_a_writable_file_is_edited_in_place_without_the_broker(): void
    {
        $path = sys_get_temp_dir().'/engine-env-'.bin2hex(random_bytes(4));
        file_put_contents($path, "# APP_URL=\n");
        $system = $this->system('');

        (new EngineEnvFile(new EnvFile($path), EngineEnvFile::CORE, $system))->set(['APP_URL' => 'https://e.example.test:2011']);

        $this->assertSame("APP_URL=https://e.example.test:2011\n", file_get_contents($path));
        $this->assertSame([], $system->ran);
        @unlink($path);
        @unlink($path.EnvFile::BACKUP_SUFFIX);
    }

    public function test_the_request_refuses_a_value_env_would_have_to_quote(): void
    {
        $compose = Privileged::engineCompose(self::ENGINE);

        foreach (['a b', "x\nCOMPOSE_PROFILES=evil", '"x"', 'a#b'] as $value) {
            try {
                $compose->setEnv(['SITES_DB_KEY_BUFFER_SIZE' => $value]);
                $this->fail('accepted '.json_encode($value));
            } catch (InvalidArgument) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** A System that answers compose.engineEnvValues with $values and records every request. */
    private function system(string $values): System
    {
        return new class($values) extends System
        {
            /** @var list<list<string>> */
            public array $ran = [];

            public function __construct(private string $values) {}

            public function engineDirPath(): string
            {
                return '/opt/panelalpha/shared-hosting';
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->ran[] = array_values((array) $cmd);

                return LocalRoot::action(array_values((array) $cmd)) === 'compose.engineEnvValues' ? $this->values : '';
            }
        };
    }
}
