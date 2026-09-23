<?php

namespace Tests\Unit\Deploy;

use App\Lib\Deploy\Compose\ComposeFileInspector;
use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\Compose\GeneratedCompose;
use App\Lib\Deploy\Platform\Runtime\Php\MysqlSidecar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ComposeFileInspector.
 *
 * The class has no Laravel dependencies, so this test extends the plain
 * PHPUnit TestCase — no app boot required. Each test uses an isolated temp
 * directory created in setUp() and removed in tearDown() for the methods
 * that read real files; the YAML-string variants need no filesystem at all.
 *
 * DetectProjectStrategyTest exercises most of these through detect(); this
 * file targets each classifier directly so a regression here fails close to
 * the broken method instead of only surfacing as a wrong overall strategy.
 */
class ComposeFileInspectorTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/compose-inspector-' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
        parent::tearDown();
    }

    private function writeFile(string $relative, string $contents = ''): string
    {
        $path = $this->tmpDir . '/' . $relative;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($path, $contents);

        return $path;
    }

    private function removeDir(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }

    public function test_missing_compose_dockerfile_refs_resolves_context_relative_dockerfile(): void
    {
        $this->writeFile('docker-compose.yml', <<<'YAML'
services:
  app:
    build:
      context: ./console
      dockerfile: Dockerfile
YAML
        );

        $missing = ComposeFileInspector::missingComposeDockerfileRefs(
            $this->tmpDir . '/docker-compose.yml',
            $this->tmpDir
        );
        $this->assertSame(['console/Dockerfile'], $missing);

        $this->writeFile('console/Dockerfile', 'FROM php:8.3-cli');
        $stillMissing = ComposeFileInspector::missingComposeDockerfileRefs(
            $this->tmpDir . '/docker-compose.yml',
            $this->tmpDir
        );
        $this->assertSame([], $stillMissing);
    }

    public function test_compose_build_dockerfile_absolute_handles_string_and_array_build(): void
    {
        $this->assertSame(
            $this->tmpDir . '/Dockerfile',
            ComposeFileInspector::composeBuildDockerfileAbsolute($this->tmpDir, '.')
        );
        $this->assertSame(
            $this->tmpDir . '/docker/Dockerfile.prod',
            ComposeFileInspector::composeBuildDockerfileAbsolute($this->tmpDir, [
                'context' => 'docker',
                'dockerfile' => 'Dockerfile.prod',
            ])
        );
        // Remote build contexts have no local Dockerfile to check.
        $this->assertNull(ComposeFileInspector::composeBuildDockerfileAbsolute($this->tmpDir, [
            'context' => 'https://example.test/repo.git',
        ]));
        // Inline Dockerfiles have no on-disk file either.
        $this->assertNull(ComposeFileInspector::composeBuildDockerfileAbsolute($this->tmpDir, [
            'dockerfile_inline' => 'FROM scratch',
        ]));
    }

    public function test_compose_build_context_is_project_root_accepts_dot_variants_only(): void
    {
        $this->assertTrue(ComposeFileInspector::composeBuildContextIsProjectRoot('.'));
        $this->assertTrue(ComposeFileInspector::composeBuildContextIsProjectRoot('./'));
        $this->assertTrue(ComposeFileInspector::composeBuildContextIsProjectRoot(['context' => '.']));
        $this->assertTrue(ComposeFileInspector::composeBuildContextIsProjectRoot([]));
        $this->assertFalse(ComposeFileInspector::composeBuildContextIsProjectRoot('./console'));
        $this->assertFalse(ComposeFileInspector::composeBuildContextIsProjectRoot(['context' => 'docker']));
    }

    public function test_is_local_dev_compose_yaml_matches_bind_mount_build_but_not_image_only_service(): void
    {
        $devCompose = <<<'YAML'
services:
  app:
    build: .
    volumes:
      - .:/var/www/html
YAML;
        $this->assertTrue(ComposeFileInspector::isLocalDevComposeYaml($devCompose));

        $prodCompose = <<<'YAML'
services:
  app:
    image: ghcr.io/acme/app:stable
YAML;
        $this->assertFalse(ComposeFileInspector::isLocalDevComposeYaml($prodCompose));
        $this->assertFalse(ComposeFileInspector::isLocalDevComposeYaml(''));
        $this->assertFalse(ComposeFileInspector::isLocalDevComposeYaml('not: [valid'));
    }

    public function test_is_local_dev_compose_yaml_matches_undefaulted_host_uid_build_arg(): void
    {
        $compose = <<<'YAML'
services:
  app:
    build:
      context: .
      args:
        WWWUSER: ${WWWUSER}
YAML;

        $this->assertTrue(ComposeFileInspector::isLocalDevComposeYaml($compose));
    }

    public function test_is_local_dev_compose_reads_from_disk(): void
    {
        $path = $this->writeFile('docker-compose.yml', <<<'YAML'
services:
  app:
    build: .
    volumes:
      - .:/var/www/html
YAML
        );

        $this->assertTrue(ComposeFileInspector::isLocalDevCompose($path));
        $this->assertFalse(ComposeFileInspector::isLocalDevCompose($this->tmpDir . '/missing.yml'));
    }

    public function test_a_read_only_single_file_mount_into_a_build_service_is_not_a_dev_compose(): void
    {
        // The #219 regression: a recipe's build service injects one generated
        // file read-only. That is not live reload, so the compose must not be
        // demoted and skipped.
        $compose = <<<'YAML'
services:
  app:
    build: .
    volumes:
      - ./docker/entrypoint.sh:/entrypoint.sh:ro
YAML;
        $this->assertFalse(ComposeFileInspector::isLocalDevComposeYaml($compose));
        $this->assertNull(ComposeFileInspector::localDevComposeReasonYaml($compose));

        // Long syntax, read_only: true, of a single config file: also not demoted.
        $longForm = <<<'YAML'
services:
  app:
    build: .
    volumes:
      - type: bind
        source: ./config/answers.json
        target: /app/answers.json
        read_only: true
YAML;
        $this->assertFalse(ComposeFileInspector::isLocalDevComposeYaml($longForm));

        // A read-write subdirectory that is still a single file is injection,
        // not a mounted source tree.
        $writableFile = <<<'YAML'
services:
  app:
    build: .
    volumes:
      - ./generated/config.php:/var/www/html/config.php
YAML;
        $this->assertFalse(ComposeFileInspector::isLocalDevComposeYaml($writableFile));
    }

    public function test_a_build_service_mounting_the_project_root_or_a_source_dir_is_still_a_dev_compose(): void
    {
        $root = <<<'YAML'
services:
  app:
    build: .
    volumes:
      - .:/var/www/html
YAML;
        $this->assertTrue(ComposeFileInspector::isLocalDevComposeYaml($root));
        $this->assertSame(
            'service `app` mounts the project root',
            ComposeFileInspector::localDevComposeReasonYaml($root)
        );

        // A whole source directory mounted read-write (OpenCart's `./upload`,
        // a `./src`) is the genuine live-reload signal and still demotes.
        $srcDir = <<<'YAML'
services:
  web:
    build: .
    volumes:
      - ./src:/var/www/html
YAML;
        $this->assertTrue(ComposeFileInspector::isLocalDevComposeYaml($srcDir));
        $this->assertSame(
            'service `web` mounts `./src`',
            ComposeFileInspector::localDevComposeReasonYaml($srcDir)
        );
    }

    public function test_is_sidecars_only_compose_yaml_requires_every_service_to_be_a_known_datastore(): void
    {
        $sidecarsOnly = <<<'YAML'
services:
  db:
    image: postgres:16-alpine
  cache:
    image: redis:7-alpine
YAML;
        $this->assertTrue(ComposeFileInspector::isSidecarsOnlyComposeYaml($sidecarsOnly));

        $mixed = <<<'YAML'
services:
  db:
    image: postgres:16-alpine
  app:
    image: ghcr.io/acme/app:stable
YAML;
        $this->assertFalse(ComposeFileInspector::isSidecarsOnlyComposeYaml($mixed));

        $withBuild = <<<'YAML'
services:
  db:
    build: .
YAML;
        $this->assertFalse(ComposeFileInspector::isSidecarsOnlyComposeYaml($withBuild));
        $this->assertFalse(ComposeFileInspector::isSidecarsOnlyComposeYaml('services: {}'));
    }

    public function test_datastores_with_their_consoles_are_still_only_sidecars(): void
    {
        // Vendure's compose, in miniature: five database servers, a search
        // engine, a cache and four consoles, and no Vendure. Accepting it as
        // the deployment ran a stack containing no application at all.
        $devEnvironment = <<<'YAML'
services:
  mariadb:
    image: mariadb:latest
  postgres_16:
    image: postgres:16
  elasticsearch:
    image: elasticsearch:8.13.0
  redis:
    image: redis:7
  keycloak:
    image: quay.io/keycloak/keycloak
  grafana:
    image: grafana/grafana
  jaeger:
    image: jaegertracing/all-in-one
  adminer:
    image: adminer:latest
YAML;
        $this->assertTrue(ComposeFileInspector::isSidecarsOnlyComposeYaml($devEnvironment));

        // One real application service is still enough to make it a stack.
        $withApp = $devEnvironment . "\n  shop:\n    image: ghcr.io/acme/shop:stable\n";
        $this->assertFalse(ComposeFileInspector::isSidecarsOnlyComposeYaml($withApp));
    }

    public function test_is_workstation_app_service_matches_bind_mount_or_undefaulted_host_uid(): void
    {
        $this->assertTrue(ComposeFileInspector::isWorkstationAppService([
            'build' => '.',
            'volumes' => ['.:/var/www/html'],
        ]));
        $this->assertTrue(ComposeFileInspector::isWorkstationAppService([
            'build' => ['context' => '.', 'args' => ['PUID' => '${PUID}']],
        ]));
        $this->assertFalse(ComposeFileInspector::isWorkstationAppService([
            'image' => 'postgres:16-alpine',
        ]));
        $this->assertFalse(ComposeFileInspector::isWorkstationAppService([
            'build' => '.',
            'volumes' => ['data:/var/lib/data'],
        ]));
    }

    public function test_is_host_uid_mapped_dockerfile_requires_group_creation_and_undefaulted_var(): void
    {
        $mapped = $this->writeFile('Dockerfile.mapped', <<<'DOCKER'
FROM php:8.3-cli
ARG WWWUSER
RUN groupadd -g ${WWWGROUP} sail && useradd -u $WWWUSER -g sail sail
DOCKER
        );
        $this->assertTrue(ComposeFileInspector::isHostUidMappedDockerfile($mapped));

        $defaulted = $this->writeFile('Dockerfile.defaulted', <<<'DOCKER'
FROM php:8.3-cli
ARG WWWUSER=1000
RUN groupadd -g 1000 sail && useradd -u $WWWUSER -g sail sail
DOCKER
        );
        $this->assertFalse(ComposeFileInspector::isHostUidMappedDockerfile($defaulted));

        $plain = $this->writeFile('Dockerfile.plain', "FROM php:8.3-cli\nCMD [\"php-fpm\"]\n");
        $this->assertFalse(ComposeFileInspector::isHostUidMappedDockerfile($plain));

        $this->assertFalse(ComposeFileInspector::isHostUidMappedDockerfile($this->tmpDir . '/missing'));
    }

    /**
     * Crater, verbatim: the var is lowercase and there is no `groupadd` line,
     * only the `useradd` that uses it.
     *
     * Both details matter. `$uid` was not in the variable list at all, so the
     * file looked deployable; and once the variable expands empty the shell
     * eats the next flag instead, which is why the build failed with
     * `useradd: invalid user ID '-d'` rather than anything about a uid.
     */
    public function test_a_lowercase_uid_is_recognised_as_a_host_uid_map(): void
    {
        $crater = $this->writeFile('Dockerfile.crater', <<<'DOCKER'
FROM php:8.1-fpm

ARG user
ARG uid

RUN useradd -G www-data,root -u $uid -d /home/$user $user
DOCKER
        );

        $this->assertTrue(ComposeFileInspector::isHostUidMappedDockerfile($crater));
    }

    /** A numeric default is still a decision, in either case. */
    public function test_a_defaulted_lowercase_uid_is_not_mapped(): void
    {
        $defaulted = $this->writeFile('Dockerfile.crater-defaulted', <<<'DOCKER'
FROM php:8.1-fpm
ARG uid=1000
RUN useradd -u $uid -d /home/app app
DOCKER
        );

        $this->assertFalse(ComposeFileInspector::isHostUidMappedDockerfile($defaulted));
    }

    public function test_is_generated_bootstrap_compose_matches_engine_markers_and_welcome_nginx(): void
    {
        $labelled = $this->writeFile('compose.labelled.yml', "# panelalpha.generated\nservices: {}\n");
        $this->assertTrue(ComposeFileInspector::isGeneratedBootstrapCompose($labelled));

        // The unlabelled shape the engine wrote before the label existed.
        $welcome = $this->writeFile('compose.welcome.yml', <<<'YAML'
services:
  app:
    image: nginx:alpine
    ports:
      - '8080:80'
    volumes:
      - './:/usr/share/nginx/html/:ro'
YAML
        );
        $this->assertTrue(ComposeFileInspector::isGeneratedBootstrapCompose($welcome));

        $userOwned = $this->writeFile('compose.user.yml', <<<'YAML'
services:
  app:
    build: .
YAML
        );
        $this->assertFalse(ComposeFileInspector::isGeneratedBootstrapCompose($userOwned));

        $this->assertFalse(ComposeFileInspector::isGeneratedBootstrapCompose($this->tmpDir . '/missing.yml'));
    }

    /**
     * Found live (ticket 08): a client's own static site on the same image,
     * serving a directory of its own rather than the project root, was taken
     * for the engine's bootstrap and replaced by the placeholder page.
     *
     * @return array<string, array{0: string}>
     */
    public static function clientNginxStacks(): array
    {
        return [
            'serves a subdirectory' => ["services:\n  web:\n    image: nginx:alpine\n    ports: ['8080:80']\n    volumes:\n      - ./html:/usr/share/nginx/html:ro\n"],
            'serves ./public' => ["services:\n  web:\n    image: nginx:alpine\n    volumes:\n      - ./public:/usr/share/nginx/html\n"],
            'root plus a second service' => ["services:\n  app:\n    image: nginx:alpine\n    volumes:\n      - ./:/usr/share/nginx/html/:ro\n  cache:\n    image: redis:7\n"],
            'mentions the path only in a comment' => ["# serves /usr/share/nginx/html\nservices:\n  web:\n    image: nginx:alpine\n    ports: ['8080:80']\n"],
        ];
    }

    #[DataProvider('clientNginxStacks')]
    public function test_a_clients_own_nginx_stack_is_not_taken_for_the_bootstrap(string $contents): void
    {
        $path = $this->writeFile('client-nginx.yml', $contents);

        $this->assertFalse(ComposeFileInspector::isGeneratedBootstrapCompose($path));
    }

    public function test_is_generated_bootstrap_compose_treats_unreadable_file_as_engine_written(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('root ignores file permission bits.');
        }

        $path = $this->writeFile('compose.locked.yml', 'services: {}');
        chmod($path, 0000);

        try {
            $this->assertTrue(ComposeFileInspector::isGeneratedBootstrapCompose($path));
        } finally {
            chmod($path, 0644);
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function generatedComposeFiles(): array
    {
        return [
            'static bootstrap' => [DeployCompose::staticNginx()],
            'own Dockerfile' => [DeployCompose::dockerfile('Dockerfile', 8080, [])],
            'railpack image' => [DeployCompose::railpack('panelalpha-acme-app:latest', 3000)],
            'framework recipe' => [DeployCompose::framework(['runtime' => 'node'], 3000, null)],
        ];
    }

    /**
     * The writer/reader agreement, asserted on real generator output rather
     * than on a hand-written fixture.
     *
     * Every generator labels what it writes and the inspector recognises a
     * file by that label. They agreed by seven separately hand-typed copies
     * of the same string until {@see GeneratedCompose::LABEL} gave it one
     * name; getting this wrong does not fail loudly — the engine simply
     * starts treating its own output as a compose file the customer wrote,
     * and stops overwriting it.
     */
    #[DataProvider('generatedComposeFiles')]
    public function test_every_generated_compose_is_recognised_as_generated(string $contents): void
    {
        $path = $this->writeFile('generated.yml', $contents);

        $this->assertStringContainsString(GeneratedCompose::LABEL, $contents, 'the generator labels its output');
        $this->assertTrue(
            ComposeFileInspector::isGeneratedBootstrapCompose($path),
            'the inspector must recognise what the generators write'
        );
    }

    /**
     * The engine's MySQL sidecar carries the same label, so a compose file
     * holding one is engine output too.
     */
    public function test_the_mysql_sidecar_is_labelled_like_every_other_generated_service(): void
    {
        $service = MysqlSidecar::service(['database' => 'app', 'username' => 'u', 'password' => 'p']);

        $this->assertArrayHasKey(GeneratedCompose::LABEL, $service['labels']);
    }

    /**
     * A compose file the customer wrote carries no such label, and must not
     * be mistaken for the engine's — overwriting it would lose their work.
     */
    public function test_a_hand_written_compose_is_not_mistaken_for_generated(): void
    {
        $path = $this->writeFile('theirs.yml', "services:\n  web:\n    image: myorg/app\n    ports: ['3000:3000']\n");

        $this->assertFalse(ComposeFileInspector::isGeneratedBootstrapCompose($path));
    }
}
