<?php

namespace Tests\Integration\Deploy\Compose;

use App\Lib\Deploy\Compose\ComposePlaceholders;
use App\Lib\Deploy\Compose\RuntimeSidecars;
use App\System\Project\Dind\RuntimeSidecars as DindRuntimeSidecars;
use PHPUnit\Framework\TestCase;
use Tests\Support\RuntimeSidecarsFixtures;

/**
 * What a project's own compose file contributes to a deploy.
 *
 * What is dropped matters as much as what is kept: the workstation
 * application service, the mail catcher, the published host ports, the
 * networks that do not exist inside an account. And what survives has its
 * credentials pinned, because a datastore that regenerates its password on
 * every deploy locks the application out of data it wrote yesterday.
 */
class RuntimeSidecarsTest extends TestCase
{
    use RuntimeSidecarsFixtures;

    public function test_a_file_is_read_from_disk(): void
    {
        $path = sys_get_temp_dir().'/pa-sidecars-'.bin2hex(random_bytes(6)).'.yaml';
        file_put_contents($path, self::STACK);

        try {
            $result = RuntimeSidecars::fromFile($path, true, null, 'github.com/acme/shop');

            $this->assertSame(['db'], array_keys($result['services']));
        } finally {
            unlink($path);
        }
    }

    /**
     * The glob for `docker-compose.*.yml` stack slices must not pick up the
     * recipe's own `docker-compose.override.yml` — which the engine copied in
     * and whose `image:`-bearing services would be re-emitted as sidecars.
     */
    public function test_a_recipes_own_compose_override_is_not_read_as_a_stack_template(): void
    {
        $dir = sys_get_temp_dir().'/pa-sidecars-'.bin2hex(random_bytes(6));
        mkdir($dir);
        file_put_contents(
            $dir.'/docker-compose.override.yml',
            "services:\n  ready:\n    image: alpine:3\n    command: [\"true\"]\n"
        );
        // A genuine engine slice the glob should still return.
        file_put_contents($dir.'/docker-compose.services.yml', "services:\n  db:\n    image: postgres:16\n");

        try {
            $names = (new \ReflectionMethod(DindRuntimeSidecars::class, 'exampleComposeFilenames'))
                ->invoke(null, $dir);

            $this->assertNotContains('docker-compose.override.yml', $names);
            $this->assertContains('docker-compose.services.yml', $names);
        } finally {
            unlink($dir.'/docker-compose.override.yml');
            unlink($dir.'/docker-compose.services.yml');
            rmdir($dir);
        }
    }

    /** ocean_ctf: the server got the generated password and its own check `redis-cli -a ''` answered WRONGPASS. */
    public function test_a_kept_sidecars_health_check_gets_the_same_generated_secret(): void
    {
        $yaml = <<<'YAML'
        services:
          redis:
            image: redis:6
            command: redis-server --requirepass ${REDIS_PASSWORD}
            healthcheck:
              test: ["CMD", "redis-cli", "-a", "${REDIS_PASSWORD}", "ping"]
              interval: 10s
          cache:
            image: redis:7
            command: redis-server --requirepass ${CACHE_PASSWORD}
            healthcheck:
              test: redis-cli -a ${CACHE_PASSWORD} ping
        YAML;

        $result = RuntimeSidecars::fromYaml($yaml, true, null, 'github.com/hexbay/ocean_ctf', null, 'account-seed');

        $redisPassword = ComposePlaceholders::requiredSecretValue('REDIS_PASSWORD', 'account-seed');
        $cachePassword = ComposePlaceholders::requiredSecretValue('CACHE_PASSWORD', 'account-seed');
        $this->assertSame('redis-server --requirepass '.$redisPassword, $result['services']['redis']['command']);
        $this->assertSame(['CMD', 'redis-cli', '-a', $redisPassword, 'ping'], $result['services']['redis']['healthcheck']['test']);
        $this->assertSame('10s', $result['services']['redis']['healthcheck']['interval']);
        $this->assertSame('redis-cli -a '.$cachePassword.' ping', $result['services']['cache']['healthcheck']['test']);
    }
}
