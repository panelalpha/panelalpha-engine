<?php

namespace Tests\Integration\System\Project\Dind;

use App\Lib\Deploy\Compose\ServiceDependencies;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\RuntimeSidecars;
use App\System\Project\Dind\ShellOperations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * A repository Dockerfile takes its database from a compose slice the repository ships, and the first slice that
 * yielded any service won, alphabetically. gorkem-bwl/atlas got `docker-compose.https.yml` (a Caddy in front of
 * `atlas`) instead of `docker-compose.production.yml` (postgres, redis) and every start failed with
 * `getaddrinfo ENOTFOUND postgres`; fosrl/pangolin got `compose.drizzle.yaml`, whose console depends on `db`, and
 * Compose refused the project: `service "drizzle-gateway" depends on undefined service "db"`.
 */
class RuntimeSidecarsOverlaySliceTest extends TestCase
{
    private const ATLAS_HTTPS = <<<'YAML'
        services:
          caddy:
            image: caddy:2-alpine
            command: caddy reverse-proxy --from ${ATLAS_DOMAIN:-localhost} --to atlas:3001
            depends_on:
              - atlas
        YAML;

    private const PANGOLIN_DRIZZLE = <<<'YAML'
        services:
          drizzle-gateway:
            image: ghcr.io/drizzle-team/gateway:latest
            depends_on:
              db:
                condition: service_healthy
        YAML;

    private const ATLAS_PRODUCTION = <<<'YAML'
        services:
          atlas:
            image: ghcr.io/gorkem-bwl/atlas:latest
            depends_on:
              postgres:
                condition: service_healthy
              redis:
                condition: service_healthy
          postgres:
            image: postgres:16-alpine
          redis:
            image: redis:7-alpine
        YAML;

    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            array_map('unlink', glob($dir.'/*') ?: []);
            rmdir($dir);
        }
        parent::tearDown();
    }

    public function test_a_slice_naming_services_it_does_not_define_extends_another_file(): void
    {
        $this->assertSame(['atlas'], $this->undefined(self::ATLAS_HTTPS));
        $this->assertSame(['db'], $this->undefined(self::PANGOLIN_DRIZZLE));
    }

    public function test_a_stack_that_defines_what_it_names_is_whole(): void
    {
        $this->assertSame([], $this->undefined(self::ATLAS_PRODUCTION));
    }

    public function test_an_overlay_slice_is_not_a_source_of_backing_services(): void
    {
        $sidecars = new RuntimeSidecars($this->dind());
        $extends = new \ReflectionMethod(RuntimeSidecars::class, 'extendsAnotherFile');

        $this->assertTrue($extends->invoke($sidecars, 'docker-compose.https.yml', self::ATLAS_HTTPS));
        $this->assertTrue($extends->invoke($sidecars, 'compose.drizzle.yaml', self::PANGOLIN_DRIZZLE));
        $this->assertFalse($extends->invoke($sidecars, 'docker-compose.production.yml', self::ATLAS_PRODUCTION));
    }

    public function test_the_production_slice_is_read_before_the_others(): void
    {
        $dir = $this->projectWith([
            'docker-compose.https.yml' => self::ATLAS_HTTPS,
            'docker-compose.dev.yml' => "services:\n  db:\n    image: postgres:16\n",
            'docker-compose.production.yml' => self::ATLAS_PRODUCTION,
        ]);

        $names = (new \ReflectionMethod(RuntimeSidecars::class, 'exampleComposeFilenames'))->invoke(null, $dir);
        $slices = array_values(array_filter($names, static fn (string $n): bool => is_file($dir.'/'.$n)));

        $this->assertSame(['docker-compose.production.yml', 'docker-compose.https.yml', 'docker-compose.dev.yml'], $slices);
    }

    /** @return list<string> */
    private function undefined(string $yaml): array
    {
        return ServiceDependencies::undefinedIn(Yaml::parse($yaml)['services']);
    }

    /** @param array<string, string> $files */
    private function projectWith(array $files): string
    {
        $dir = sys_get_temp_dir().'/pa-slices-'.bin2hex(random_bytes(6));
        mkdir($dir);
        $this->dirs[] = $dir;
        foreach ($files as $name => $contents) {
            file_put_contents($dir.'/'.$name, $contents);
        }

        return $dir;
    }

    private function dind(): Dind
    {
        $dind = $this->createStub(Dind::class);
        $dind->method('username')->willReturn('acme');
        $dind->method('system')->willReturn($this->createStub(System::class));
        $dind->method('shell')->willReturn(new ShellOperations($dind));

        return $dind;
    }
}
