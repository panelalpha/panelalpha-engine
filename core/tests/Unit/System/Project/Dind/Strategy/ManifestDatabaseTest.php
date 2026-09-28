<?php

namespace Tests\Unit\System\Project\Dind\Strategy;

use App\Lib\Deploy\DetectProjectStrategy;
use App\Lib\Deploy\Platform\SourceRecipes;
use App\Lib\Deploy\Platform\Strategies;
use App\System;
use App\System\Filesystem as SystemFilesystem;
use App\System\Project\Dind;
use App\System\Project\Dind\ComposeWriter;
use App\System\Project\Dind\DeployStrategy;
use App\System\Project\Dind\RuntimeSidecars;
use App\System\Project\Dind\ShellOperations;
use App\System\Project\Dind\Strategy\AccountSecrets;
use App\System\Project\Dind\Strategy\DockerfileStrategy;
use App\System\Project\Dind\Strategy\EntrypointWriter;
use App\System\Project\Dind\Strategy\FrameworkStrategy;
use App\System\Project\Dind\Strategy\ManifestDatabase;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * engine#210: `database: mysql` outside the PHP strategy. The dockerfile and
 * generated-framework writers provision the account's database and hand the
 * app DATABASE_URL + DB_* + the extra_hosts pin; the rest are warned about.
 */
class ManifestDatabaseTest extends TestCase
{
    private const CREDENTIALS = [
        'connection' => 'mysql',
        'host' => 'database-users.shared-hosting.palocal',
        'port' => '3306',
        'database' => 'acme_app',
        'username' => 'acme_app',
        'password' => 'p@ss/w:rd',
    ];

    private const PIN = 'database-users.shared-hosting.palocal:172.25.0.14';

    private string $dir;

    private int $provisioned = 0;

    private ?string $compose = null;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pa-manifest-db-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->provisioned = 0;
        $this->compose = null;
        SourceRecipes::flush();
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->dir . '/{,.}*', GLOB_BRACE) as $file) {
            if (is_string($file) && is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($this->dir);
    }

    public function test_environment_is_a_percent_encoded_url_plus_db_variables(): void
    {
        $env = ManifestDatabase::environment(self::CREDENTIALS);

        $this->assertSame(
            'mysql://acme_app:p%40ss%2Fw%3Ard@database-users.shared-hosting.palocal:3306/acme_app?charset=utf8mb4',
            $env['DATABASE_URL']
        );
        $this->assertSame('mysql', $env['DB_CONNECTION']);
        $this->assertSame('acme_app', $env['DB_DATABASE']);
        $this->assertSame('p@ss/w:rd', $env['DB_PASSWORD']);
    }

    public function test_only_writers_that_generate_the_app_service_honour_it(): void
    {
        foreach ([Strategies::PHP, Strategies::LARAVEL, Strategies::DOCKERFILE, Strategies::EXPRESS, Strategies::DJANGO] as $honoured) {
            $this->assertTrue(ManifestDatabase::isHonouredBy($honoured), $honoured);
            $this->assertNull(ManifestDatabase::inertWarning(['strategy' => $honoured, 'database' => 'mysql']));
        }
        foreach ([Strategies::COMPOSE, Strategies::RAILS, Strategies::RUBY, Strategies::RAILPACK, Strategies::STATIC, Strategies::FALLBACK] as $inert) {
            $this->assertFalse(ManifestDatabase::isHonouredBy($inert), $inert);
            $this->assertStringContainsString(
                "strategy {$inert} does not provision one",
                (string) ManifestDatabase::inertWarning(['strategy' => $inert, 'database' => 'mysql'])
            );
        }
        $this->assertNull(ManifestDatabase::inertWarning(['strategy' => Strategies::COMPOSE, 'database' => null]));
    }

    /** The shipped case: Kimai's recipe through its own URL, on the dockerfile writer. */
    public function test_kimai_gets_the_apache_variant_and_the_account_database(): void
    {
        $service = $this->dockerfileService($this->kimaiDecision());

        $this->assertSame(1, $this->provisioned);
        $this->assertSame(['BASE' => 'apache'], $service['build']['args']);
        $this->assertSame(['8001:8001'], $service['ports']);
        $this->assertSame(ManifestDatabase::environment(self::CREDENTIALS)['DATABASE_URL'], $service['environment']['DATABASE_URL']);
        $this->assertSame('acme_app', $service['environment']['DB_USERNAME']);
        $this->assertSame([self::PIN], $service['extra_hosts']);
    }

    /** A project that ships its own MySQL keeps it; nothing is provisioned beside it. */
    public function test_a_shipped_mysql_sidecar_wins(): void
    {
        $service = $this->dockerfileService($this->kimaiDecision(), [
            'services' => ['db' => ['image' => 'mariadb:11']],
            'volumes' => [],
            'env' => [],
        ]);

        $this->assertSame(0, $this->provisioned);
        $this->assertArrayNotHasKey('DATABASE_URL', $service['environment'] ?? []);
        $this->assertArrayNotHasKey('extra_hosts', $service);
        // Short or long form: a sidecar with a healthcheck gets `condition:` entries.
        $dependsOn = $service['depends_on'];
        $this->assertSame(['db'], array_is_list($dependsOn) ? $dependsOn : array_keys($dependsOn));
    }

    /** A Dockerfile repository with no manifest database is untouched. */
    public function test_no_database_declared_changes_nothing(): void
    {
        file_put_contents($this->dir . '/Dockerfile', "FROM nginx:alpine\nEXPOSE 8080\n");
        $decision = DetectProjectStrategy::detect($this->dir);
        $this->assertSame(Strategies::DOCKERFILE, $decision['strategy']);

        $service = $this->dockerfileService($decision);

        $this->assertSame(0, $this->provisioned);
        $this->assertArrayNotHasKey('DATABASE_URL', $service['environment'] ?? []);
        $this->assertArrayNotHasKey('extra_hosts', $service);
        $this->assertSame('.', $service['build']);
    }

    /** A generated framework build (Express here) gets it too; the manifest's own env still wins. */
    public function test_a_generated_framework_service_gets_the_database(): void
    {
        file_put_contents($this->dir . '/package.json', '{"name":"x","dependencies":{"express":"^4"},"scripts":{"start":"node server.js"}}');
        file_put_contents($this->dir . '/server.js', "require('express')().listen(3000)\n");
        $decision = DetectProjectStrategy::detect($this->dir);
        $this->assertTrue(ManifestDatabase::isHonouredBy($decision['strategy']), $decision['strategy']);
        $decision['database'] = 'mysql';
        $decision['env'] = ['DB_HOST' => 'recipe-chose-this'];

        (new FrameworkStrategy($this->dind(['services' => [], 'volumes' => [], 'env' => []])))->apply($decision, $this->dir, null);
        $service = $this->renderedApp();

        $this->assertSame(1, $this->provisioned);
        $this->assertStringStartsWith('mysql://acme_app:', $service['environment']['DATABASE_URL']);
        $this->assertSame('recipe-chose-this', $service['environment']['DB_HOST']);
        $this->assertSame([self::PIN], $service['extra_hosts']);
    }

    /** @return array<string, mixed> */
    private function kimaiDecision(): array
    {
        file_put_contents($this->dir . '/Dockerfile', "ARG BASE=fpm\nFROM php:8.3-\${BASE}\n");
        $decision = DetectProjectStrategy::detect($this->dir, 'https://github.com/kimai/kimai');
        $this->assertSame('kimai', $decision['platform']);
        $this->assertSame('mysql', $decision['database']);

        return $decision;
    }

    /**
     * @param array<string, mixed> $decision
     * @param array{services: array<string, mixed>, volumes: array<string, mixed>, env: array<string, string>}|null $sidecars
     * @return array<string, mixed>
     */
    private function dockerfileService(array $decision, ?array $sidecars = null): array
    {
        (new DockerfileStrategy($this->dind($sidecars ?? ['services' => [], 'volumes' => [], 'env' => []])))
            ->apply($decision, $this->dir, null);

        return $this->renderedApp();
    }

    /** @return array<string, mixed> */
    private function renderedApp(): array
    {
        $this->assertNotNull($this->compose, 'no compose file was written');

        return Yaml::parse($this->compose)['services']['app'];
    }

    /**
     * @param array{services: array<string, mixed>, volumes: array<string, mixed>, env: array<string, string>} $sidecars
     */
    private function dind(array $sidecars): Dind
    {
        $model = new \App\Models\User();
        $model->username = 'acme';
        $model->details = ['UID' => 1001, 'GID' => 1001];

        $system = $this->createStub(System::class);
        $system->method('filesystem')->willReturn($this->createStub(SystemFilesystem::class));

        $writer = $this->createStub(ComposeWriter::class);
        $writer->method('writeGeneratedCompose')->willReturnCallback(function (string $dir, string $yaml): void {
            $this->compose = $yaml;
        });

        $dind = $this->createStub(Dind::class);
        $dind->method('userModel')->willReturn($model);
        $dind->method('system')->willReturn($system);
        $dind->method('publicAppUrl')->willReturn(null);
        $dind->method('composeWriter')->willReturn($writer);
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));

        $test = $this;
        $strategy = new class ($dind, $sidecars, $test) extends DeployStrategy {
            /** @param array<string, mixed> $harvested */
            public function __construct(private Dind $d, private array $harvested, private ManifestDatabaseTest $test)
            {
                parent::__construct($d);
            }

            public function secrets(): AccountSecrets
            {
                return new class ($this) extends AccountSecrets {
                    public function __construct(object $unused)
                    {
                    }

                    public function userEnvVars(): array
                    {
                        return [];
                    }

                    // Reached once composeDecision() adds PA_INSTANCE_SECRET; the stub has no Dind.
                    public function instanceSecret(): string
                    {
                        return str_repeat('0', 64);
                    }
                };
            }

            public function entrypoint(): EntrypointWriter
            {
                return $this->test->entrypointStub();
            }

            public function sidecars(): RuntimeSidecars
            {
                return new class ($this->d, $this->harvested) extends RuntimeSidecars {
                    /** @param array<string, mixed> $harvested */
                    public function __construct(Dind $d, private array $harvested)
                    {
                        parent::__construct($d);
                    }

                    public function runtimeSidecarsFromProject(string $projectDir): array
                    {
                        return $this->harvested;
                    }
                };
            }

            public function database(): ManifestDatabase
            {
                $test = $this->test;

                return new class ($this->d, $test) extends ManifestDatabase {
                    public function __construct(Dind $d, private ManifestDatabaseTest $test)
                    {
                        parent::__construct($d);
                    }

                    protected function provision(): array
                    {
                        return $this->test->provision();
                    }

                    protected function extraHosts(): array
                    {
                        return [ManifestDatabaseTest::pin()];
                    }
                };
            }
        };
        $dind->method('strategy')->willReturn($strategy);

        return $dind;
    }

    public function entrypointStub(): EntrypointWriter
    {
        $entrypoint = $this->createStub(EntrypointWriter::class);
        $entrypoint->method('deployPhaseEnvironment')->willReturn([]);
        $entrypoint->method('write')->willReturn(false);

        return $entrypoint;
    }

    /** @return array{connection: string, host: string, port: string, database: string, username: string, password: string} */
    public function provision(): array
    {
        $this->provisioned++;

        return self::CREDENTIALS;
    }

    public static function pin(): string
    {
        return self::PIN;
    }
}
